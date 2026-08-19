<?php

use App\Http\Controllers\BattleController;
use Illuminate\Support\Facades\Route;

Route::middleware('telegram.auth')->group(function () {
    Route::get('/battles', [BattleController::class, 'index']);
    Route::get('/battles/{battle}', [BattleController::class, 'show']);
    Route::post('/battles/training', [BattleController::class, 'startTraining']);
    Route::post('/battles/challenge', [BattleController::class, 'challenge']);
    Route::post('/battles/{battle}/join', [BattleController::class, 'join']);
    Route::post('/battles/{battle}/cancel', [BattleController::class, 'cancel']);
    Route::post('/battles/{battle}/move', [BattleController::class, 'move']);
});
