<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'mode', 'player_a_id', 'player_b_id', 'model_a', 'model_b', 'a_hp', 'b_hp', 'status', 'winner',
    'a_pending_attack', 'a_pending_defend', 'b_pending_attack', 'b_pending_defend', 'round_deadline_at',
    'finished_at',
])]
class Battle extends Model
{
    protected function casts(): array
    {
        return [
            'a_pending_defend' => 'array',
            'b_pending_defend' => 'array',
            'round_deadline_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
