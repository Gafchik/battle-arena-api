<?php

namespace App\Services\Battle;

readonly class BattleRoundHistory
{
    public function __construct(
        public int $round,
        public Move $selfMove,
        public Move $oppMove,
        public int $selfDamageTaken,
        public int $oppDamageTaken,
    ) {
    }
}
