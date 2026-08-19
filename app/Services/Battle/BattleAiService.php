<?php

namespace App\Services\Battle;

class BattleAiService
{
    public function __construct(
        private readonly RoutMyClient $client,
    ) {
    }

    /**
     * @param  BattleRoundHistory[]  $history
     */
    public function decide(string $model, int $selfHp, int $oppHp, array $history): Move
    {
        $content = $this->client->complete($model, $this->buildPrompt($selfHp, $oppHp, $history), 120);

        if ($content === null) {
            return $this->fallback();
        }

        return $this->parse($content) ?? $this->fallback();
    }

    /**
     * @param  BattleRoundHistory[]  $history
     */
    private function buildPrompt(int $selfHp, int $oppHp, array $history): string
    {
        $zones = implode(', ', array_map(fn (Zone $z) => $z->value, Zone::all()));

        $historyText = empty($history)
            ? 'No rounds yet — this is the first round.'
            : implode("\n", array_map(
                fn (BattleRoundHistory $h) => sprintf(
                    'Round %d: you attacked %s, defended %s. Opponent attacked %s, defended %s. You took %d dmg, opponent took %d dmg.',
                    $h->round,
                    $h->selfMove->attack->value,
                    implode('+', array_map(fn (Zone $z) => $z->value, $h->selfMove->defend)),
                    $h->oppMove->attack->value,
                    implode('+', array_map(fn (Zone $z) => $z->value, $h->oppMove->defend)),
                    $h->selfDamageTaken,
                    $h->oppDamageTaken,
                ),
                $history
            ));

        return <<<PROMPT
You are fighting a 1-on-1 duel. Body zones: {$zones}.
Each round both fighters simultaneously pick ONE zone to attack and TWO zones to defend (the other two zones are left open).
If your attack lands on a zone the opponent did NOT defend: 30 damage. If it lands on a zone they DID defend: 15 damage (chip damage through the block).

Your HP: {$selfHp}, Opponent HP: {$oppHp}

Battle history:
{$historyText}

Think about the opponent's pattern and try to predict/outguess them. Reply with ONLY JSON: {"attack": "head|chest|groin|legs", "defend": ["zone1","zone2"]}. No commentary, no markdown.
PROMPT;
    }

    private function parse(string $content): ?Move
    {
        if (! preg_match('/\{.*\}/s', $content, $matches)) {
            return null;
        }

        $data = json_decode($matches[0], true);
        if (! is_array($data)) {
            return null;
        }

        $attack = Zone::tryFrom($data['attack'] ?? '');
        if ($attack === null) {
            return null;
        }

        $defendRaw = is_array($data['defend'] ?? null) ? $data['defend'] : [];
        $defend = [];
        foreach ($defendRaw as $z) {
            $zone = Zone::tryFrom($z);
            if ($zone !== null && ! in_array($zone, $defend, true)) {
                $defend[] = $zone;
            }
        }

        if (count($defend) !== 2) {
            return null;
        }

        return new Move($attack, $defend);
    }

    private function fallback(): Move
    {
        $shuffled = Zone::all();
        shuffle($shuffled);

        return new Move($shuffled[0], [$shuffled[1], $shuffled[2]]);
    }
}
