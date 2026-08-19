<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'battle_id', 'round',
    'a_attack', 'a_defend', 'b_attack', 'b_defend',
    'a_damage', 'b_damage', 'a_blocked', 'b_blocked',
    'a_hp_after', 'b_hp_after', 'text',
])]
class BattleRound extends Model
{
    protected function casts(): array
    {
        return [
            'a_defend' => 'array',
            'b_defend' => 'array',
            'a_blocked' => 'boolean',
            'b_blocked' => 'boolean',
        ];
    }
}
