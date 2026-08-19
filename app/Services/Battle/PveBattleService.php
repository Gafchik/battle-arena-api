<?php

namespace App\Services\Battle;

use App\Models\Battle;
use App\Models\BattleRound;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PveBattleService
{
    public function __construct(
        private readonly BattleAiService $ai,
        private readonly BattleEngine $engine,
    ) {
    }

    public function start(User $player): Battle
    {
        return Battle::create([
            'mode' => 'pve',
            'player_a_id' => $player->id,
            'player_b_id' => null,
            'model_a' => null,
            'model_b' => config('services.routmy.training_model'),
            'a_hp' => 100,
            'b_hp' => 100,
            'status' => 'in_progress',
        ]);
    }

    public function submitHumanMove(Battle $battle, Move $humanMove): BattleRound
    {
        if ($battle->status !== 'in_progress') {
            throw new \RuntimeException('Battle is already finished.');
        }

        $roundNumber = BattleRound::where('battle_id', $battle->id)->count() + 1;
        $aiMove = $this->ai->decide($battle->model_b, $battle->b_hp, $battle->a_hp, $this->historyForB($battle->id));

        $resolution = $this->engine->resolveRound($roundNumber, $battle->a_hp, $battle->b_hp, $humanMove, $aiMove);

        return DB::transaction(function () use ($battle, $roundNumber, $humanMove, $aiMove, $resolution) {
            $battleRound = BattleRound::create([
                'battle_id' => $battle->id,
                'round' => $roundNumber,
                'a_attack' => $humanMove->attack->value,
                'a_defend' => array_map(fn (Zone $z) => $z->value, $humanMove->defend),
                'b_attack' => $aiMove->attack->value,
                'b_defend' => array_map(fn (Zone $z) => $z->value, $aiMove->defend),
                'a_damage' => $resolution->aDamage,
                'b_damage' => $resolution->bDamage,
                'a_blocked' => $resolution->aBlocked,
                'b_blocked' => $resolution->bBlocked,
                'a_hp_after' => $resolution->aHpAfter,
                'b_hp_after' => $resolution->bHpAfter,
                'text' => $resolution->text,
            ]);

            $finished = $resolution->aHpAfter <= 0 || $resolution->bHpAfter <= 0;

            Battle::where('id', $battle->id)->update([
                'a_hp' => $resolution->aHpAfter,
                'b_hp' => $resolution->bHpAfter,
                'status' => $finished ? 'completed' : 'in_progress',
                'winner' => $finished ? match (true) {
                    $resolution->aHpAfter <= 0 && $resolution->bHpAfter <= 0 => 'draw',
                    $resolution->aHpAfter <= 0 => 'b',
                    default => 'a',
                } : null,
                'finished_at' => $finished ? now() : null,
            ]);

            return $battleRound;
        });
    }

    /**
     * @return BattleRoundHistory[]
     */
    private function historyForB(int $battleId): array
    {
        $rows = BattleRound::where('battle_id', $battleId)
            ->orderBy('round')
            ->get(['round', 'a_attack', 'a_defend', 'b_attack', 'b_defend', 'a_damage', 'b_damage']);

        return $rows->map(fn (BattleRound $r) => new BattleRoundHistory(
            round: $r->round,
            selfMove: new Move(Zone::from($r->b_attack), array_map(fn ($z) => Zone::from($z), $r->b_defend)),
            oppMove: new Move(Zone::from($r->a_attack), array_map(fn ($z) => Zone::from($z), $r->a_defend)),
            selfDamageTaken: $r->b_damage,
            oppDamageTaken: $r->a_damage,
        ))->all();
    }
}
