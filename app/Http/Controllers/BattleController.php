<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitMoveRequest;
use App\Models\Battle;
use App\Models\BattleRound;
use App\Models\User;
use App\Services\Battle\Move;
use App\Services\Battle\PveBattleService;
use App\Services\Battle\PvpBattleService;
use App\Services\Battle\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BattleController extends Controller
{
    public function __construct(
        private readonly PveBattleService $pve,
        private readonly PvpBattleService $pvp,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $battles = Battle::query()
            ->where('player_a_id', $userId)
            ->orWhere('player_b_id', $userId)
            ->orderByDesc('created_at')
            ->get(['id', 'mode', 'player_a_id', 'model_a', 'model_b', 'a_hp', 'b_hp', 'status', 'winner', 'created_at', 'finished_at'])
            ->map(function (Battle $b) use ($userId) {
                $yourSide = $b->player_a_id === $userId ? 'a' : 'b';

                return array_merge(
                    $b->only(['id', 'mode', 'model_a', 'model_b', 'a_hp', 'b_hp', 'status', 'winner', 'created_at', 'finished_at']),
                    ['your_side' => $yourSide]
                );
            });

        return response()->json(['battles' => $battles]);
    }

    public function show(Request $request, int $battleId): JsonResponse
    {
        $battle = Battle::query()->where('id', $battleId)->first([
            'id', 'player_a_id', 'player_b_id', 'mode', 'model_a', 'model_b', 'a_hp', 'b_hp', 'status', 'winner',
            'a_pending_attack', 'a_pending_defend', 'b_pending_attack', 'b_pending_defend', 'round_deadline_at',
            'created_at', 'finished_at',
        ]);

        if ($battle === null || ! $this->ownsBattle($request, $battle)) {
            return response()->json(['error' => ['message' => 'Battle not found']], 404);
        }

        if ($battle->mode === 'pvp' && $battle->status === 'waiting_for_opponent') {
            $battle = $this->pvp->expireIfStale($battle);

            if ($battle === null) {
                return response()->json(['error' => ['message' => 'Battle not found']], 404);
            }
        }

        if ($battle->mode === 'pvp' && $battle->status === 'in_progress') {
            $battle = $this->pvp->resolveIfTimedOut($battle);
        }

        $rounds = BattleRound::query()
            ->where('battle_id', $battleId)
            ->orderBy('round')
            ->get(['round', 'a_attack', 'a_defend', 'b_attack', 'b_defend', 'a_damage', 'b_damage', 'a_blocked', 'b_blocked', 'a_hp_after', 'b_hp_after', 'text']);

        $payload = $battle->only(['id', 'player_a_id', 'player_b_id', 'mode', 'model_a', 'model_b', 'a_hp', 'b_hp', 'status', 'winner', 'created_at', 'finished_at']);

        return response()->json([
            'battle' => array_merge($payload, $this->pvpMeta($request, $battle)),
            'rounds' => $rounds,
        ]);
    }

    public function startTraining(Request $request): JsonResponse
    {
        $battle = $this->pve->start($request->user());

        return response()->json(['battle' => $battle->only(['id', 'mode', 'model_a', 'model_b', 'a_hp', 'b_hp', 'status'])]);
    }

    public function challenge(Request $request): JsonResponse
    {
        $battle = $this->pvp->challenge($request->user());

        return response()->json([
            'battle' => array_merge(
                $battle->only(['id', 'mode', 'status']),
                ['challenge_expires_at' => $this->pvp->challengeExpiresAt($battle)?->toIso8601String()],
            ),
        ]);
    }

    public function cancel(Request $request, int $battleId): JsonResponse
    {
        $battle = Battle::query()->where('id', $battleId)->first(['id', 'player_a_id', 'status']);

        if ($battle === null) {
            return response()->json(['error' => ['message' => 'Challenge not found']], 404);
        }

        try {
            $this->pvp->cancel($battle, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['error' => ['message' => $e->getMessage()]], 400);
        }

        return response()->json(['ok' => true]);
    }

    public function join(Request $request, int $battleId): JsonResponse
    {
        $battle = Battle::query()->where('id', $battleId)->first(['id', 'player_a_id', 'player_b_id', 'status', 'created_at']);

        if ($battle !== null) {
            $battle = $this->pvp->expireIfStale($battle);
        }

        if ($battle === null) {
            return response()->json(['error' => ['message' => 'Challenge not found']], 404);
        }

        try {
            $battle = $this->pvp->join($battle, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['error' => ['message' => $e->getMessage()]], 400);
        }

        return response()->json(['battle' => $battle->only(['id', 'mode', 'a_hp', 'b_hp', 'status'])]);
    }

    public function move(SubmitMoveRequest $request, int $battleId): JsonResponse
    {
        $battle = Battle::query()->where('id', $battleId)->first([
            'id', 'player_a_id', 'player_b_id', 'mode', 'model_a', 'model_b', 'a_hp', 'b_hp', 'status',
            'a_pending_attack', 'a_pending_defend', 'b_pending_attack', 'b_pending_defend', 'round_deadline_at',
        ]);

        if ($battle === null || ! $this->ownsBattle($request, $battle)) {
            return response()->json(['error' => ['message' => 'Battle not found']], 404);
        }

        $move = new Move(
            Zone::from($request->validated('attack')),
            array_map(fn ($z) => Zone::from($z), $request->validated('defend')),
        );

        try {
            if ($battle->mode === 'pve') {
                $round = $this->pve->submitHumanMove($battle, $move);
                $updated = Battle::query()->where('id', $battleId)->first(['a_hp', 'b_hp', 'status', 'winner']);

                return response()->json([
                    'round' => $round->only(['round', 'a_attack', 'a_defend', 'b_attack', 'b_defend', 'a_damage', 'b_damage', 'a_blocked', 'b_blocked', 'a_hp_after', 'b_hp_after', 'text']),
                    'battle' => $updated,
                ]);
            }

            $updated = $this->pvp->submitMove($battle, $request->user(), $move);

            return response()->json([
                'battle' => array_merge($updated->only(['a_hp', 'b_hp', 'status', 'winner']), $this->pvpMeta($request, $updated)),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => ['message' => $e->getMessage()]], 400);
        }
    }

    private function ownsBattle(Request $request, Battle $battle): bool
    {
        $userId = $request->user()->id;

        return $battle->player_a_id === $userId || $battle->player_b_id === $userId;
    }

    /**
     * @return array{your_side?: string, my_pending_submitted?: bool, round_deadline_at?: ?string, opponent?: ?array}
     */
    private function pvpMeta(Request $request, Battle $battle): array
    {
        if ($battle->mode !== 'pvp') {
            return [];
        }

        if ($battle->status === 'waiting_for_opponent') {
            return [
                'your_side' => 'a',
                'challenge_expires_at' => $this->pvp->challengeExpiresAt($battle)?->toIso8601String(),
            ];
        }

        $side = $request->user()->id === $battle->player_a_id ? 'a' : 'b';
        $opponentId = $side === 'a' ? $battle->player_b_id : $battle->player_a_id;

        $opponent = $opponentId === null
            ? null
            : User::query()->where('id', $opponentId)->first(['first_name', 'username', 'photo_url']);

        return [
            'your_side' => $side,
            'my_pending_submitted' => $battle->{"{$side}_pending_attack"} !== null,
            'round_deadline_at' => $battle->round_deadline_at,
            'opponent' => $opponent,
        ];
    }
}
