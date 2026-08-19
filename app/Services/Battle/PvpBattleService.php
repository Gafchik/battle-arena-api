<?php

namespace App\Services\Battle;

use App\Models\Battle;
use App\Models\BattleRound;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PvpBattleService
{
    private const ROUND_TIMEOUT_SECONDS = 20;

    public function __construct(
        private readonly BattleEngine $engine,
    ) {
    }

    public function challenge(User $user): Battle
    {
        return Battle::create([
            'mode' => 'pvp',
            'player_a_id' => $user->id,
            'player_b_id' => null,
            'a_hp' => 100,
            'b_hp' => 100,
            'status' => 'waiting_for_opponent',
        ]);
    }

    public function join(Battle $battle, User $user): Battle
    {
        if ($battle->status !== 'waiting_for_opponent') {
            throw new \RuntimeException('This challenge is no longer open.');
        }

        if ($battle->player_a_id === $user->id) {
            throw new \RuntimeException('You cannot join your own challenge.');
        }

        Battle::where('id', $battle->id)->update([
            'player_b_id' => $user->id,
            'status' => 'in_progress',
            'round_deadline_at' => now()->addSeconds(self::ROUND_TIMEOUT_SECONDS),
        ]);

        return Battle::findOrFail($battle->id);
    }

    /**
     * Submit a move for whichever side the user is on. Resolves the round
     * immediately if both sides are now in. Also lazily resolves the
     * *previous* round first if its deadline already passed.
     */
    public function submitMove(Battle $battle, User $user, Move $move): Battle
    {
        $battle = $this->resolveIfTimedOut($battle);

        if ($battle->status !== 'in_progress') {
            throw new \RuntimeException('Battle is not in progress.');
        }

        $side = match ($user->id) {
            $battle->player_a_id => 'a',
            $battle->player_b_id => 'b',
            default => throw new \RuntimeException('You are not a participant in this battle.'),
        };

        Battle::where('id', $battle->id)->update([
            "{$side}_pending_attack" => $move->attack->value,
            "{$side}_pending_defend" => array_map(fn (Zone $z) => $z->value, $move->defend),
        ]);

        $battle = Battle::findOrFail($battle->id);

        $aMove = $this->moveFromPending($battle, 'a');
        $bMove = $this->moveFromPending($battle, 'b');

        if ($aMove !== null && $bMove !== null) {
            $battle = $this->resolveRound($battle, $aMove, $bMove);
        }

        return $battle;
    }

    /**
     * Called on every poll (GET) too, so a passive player also sees the
     * round resolve once the deadline passes, without needing to act.
     */
    public function resolveIfTimedOut(Battle $battle): Battle
    {
        if ($battle->status !== 'in_progress' || $battle->round_deadline_at === null) {
            return $battle;
        }

        if (now()->lessThan($battle->round_deadline_at)) {
            return $battle;
        }

        return $this->resolveRound($battle, $this->moveFromPending($battle, 'a'), $this->moveFromPending($battle, 'b'));
    }

    private function moveFromPending(Battle $battle, string $side): ?Move
    {
        $attack = $battle->{"{$side}_pending_attack"};
        $defend = $battle->{"{$side}_pending_defend"};

        if ($attack === null || $defend === null || count($defend) !== 2) {
            return null;
        }

        return new Move(Zone::from($attack), array_map(fn ($z) => Zone::from($z), $defend));
    }

    private function resolveRound(Battle $battle, ?Move $aMove, ?Move $bMove): Battle
    {
        $roundNumber = BattleRound::where('battle_id', $battle->id)->count() + 1;

        // Mutual timeout: both sides forfeit immediately, regardless of HP.
        if ($aMove === null && $bMove === null) {
            $resolution = $this->engine->resolveRound($roundNumber, $battle->a_hp, $battle->b_hp, null, null);

            return DB::transaction(function () use ($battle, $roundNumber, $resolution) {
                $this->persistRound($battle->id, $roundNumber, null, null, $resolution);

                Battle::where('id', $battle->id)->update([
                    'status' => 'completed',
                    'winner' => 'forfeit_both',
                    'finished_at' => now(),
                    'a_pending_attack' => null, 'a_pending_defend' => null,
                    'b_pending_attack' => null, 'b_pending_defend' => null,
                    'round_deadline_at' => null,
                ]);

                return Battle::findOrFail($battle->id);
            });
        }

        $resolution = $this->engine->resolveRound($roundNumber, $battle->a_hp, $battle->b_hp, $aMove, $bMove);
        $finished = $resolution->aHpAfter <= 0 || $resolution->bHpAfter <= 0;

        return DB::transaction(function () use ($battle, $roundNumber, $aMove, $bMove, $resolution, $finished) {
            $this->persistRound($battle->id, $roundNumber, $aMove, $bMove, $resolution);

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
                'a_pending_attack' => null, 'a_pending_defend' => null,
                'b_pending_attack' => null, 'b_pending_defend' => null,
                'round_deadline_at' => $finished ? null : now()->addSeconds(self::ROUND_TIMEOUT_SECONDS),
            ]);

            return Battle::findOrFail($battle->id);
        });
    }

    private function persistRound(int $battleId, int $round, ?Move $aMove, ?Move $bMove, RoundResolution $resolution): void
    {
        BattleRound::create([
            'battle_id' => $battleId,
            'round' => $round,
            'a_attack' => $aMove?->attack->value,
            'a_defend' => $aMove === null ? null : array_map(fn (Zone $z) => $z->value, $aMove->defend),
            'b_attack' => $bMove?->attack->value,
            'b_defend' => $bMove === null ? null : array_map(fn (Zone $z) => $z->value, $bMove->defend),
            'a_damage' => $resolution->aDamage,
            'b_damage' => $resolution->bDamage,
            'a_blocked' => $resolution->aBlocked,
            'b_blocked' => $resolution->bBlocked,
            'a_hp_after' => $resolution->aHpAfter,
            'b_hp_after' => $resolution->bHpAfter,
            'text' => $resolution->text,
        ]);
    }
}
