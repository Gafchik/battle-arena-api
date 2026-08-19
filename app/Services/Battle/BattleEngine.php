<?php

namespace App\Services\Battle;

class BattleEngine
{
    public const UNBLOCKED_DMG = 30;

    public const BLOCKED_DMG = 15;

    /**
     * A null move means that side didn't act in time: it deals 0 damage and
     * defends nothing (takes a full unblocked hit if the opponent attacked).
     */
    public function resolveRound(int $round, int $aHp, int $bHp, ?Move $aMove, ?Move $bMove): RoundResolution
    {
        $bDamage = $aMove === null ? 0 : (($bMove?->defends($aMove->attack) ?? false) ? self::BLOCKED_DMG : self::UNBLOCKED_DMG);
        $aDamage = $bMove === null ? 0 : (($aMove?->defends($bMove->attack) ?? false) ? self::BLOCKED_DMG : self::UNBLOCKED_DMG);

        $bBlocked = $aMove !== null && $bDamage === self::BLOCKED_DMG;
        $aBlocked = $bMove !== null && $aDamage === self::BLOCKED_DMG;

        $bHpAfter = max(0, $bHp - $bDamage);
        $aHpAfter = max(0, $aHp - $aDamage);

        $text = sprintf(
            "Раунд %d: A %s. B %s.\n".
            '→ Удар A %s — B теряет %d HP (%d осталось)'."\n".
            '→ Удар B %s — A теряет %d HP (%d осталось)',
            $round,
            $this->describeMove($aMove),
            $this->describeMove($bMove),
            $aMove === null ? 'не бил' : ($bBlocked ? 'заблокирован' : 'проходит'), $bDamage, $bHpAfter,
            $bMove === null ? 'не бил' : ($aBlocked ? 'заблокирован' : 'проходит'), $aDamage, $aHpAfter,
        );

        return new RoundResolution($aDamage, $bDamage, $aBlocked, $bBlocked, $aHpAfter, $bHpAfter, $text);
    }

    private function describeMove(?Move $move): string
    {
        if ($move === null) {
            return 'не успел сходить (не бил и не защищался)';
        }

        return sprintf(
            'бьёт в %s, защищает %s и %s',
            $move->attack->label(), $move->defend[0]->label(), $move->defend[1]->label(),
        );
    }
}
