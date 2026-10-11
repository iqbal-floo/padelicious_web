<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClubController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\MatchTypeController;
use App\Http\Controllers\Api\PlayerController;
use App\Http\Controllers\Api\RoundController;
use App\Http\Controllers\Api\SessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (v1)
|--------------------------------------------------------------------------
| Consumed by the Padelicious Flutter client. Read-only for now; write
| endpoints (create session, record points) come in a later pass.
*/

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:6,1')
        ->name('api.auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('api.auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');

        Route::get('match-types', [MatchTypeController::class, 'index'])->name('api.match-types.index');

        Route::get('players', [PlayerController::class, 'index'])->name('api.players.index');
        Route::get('players/{player}', [PlayerController::class, 'show'])->name('api.players.show');

        Route::get('clubs', [ClubController::class, 'index'])->name('api.clubs.index');
        Route::get('clubs/{club}', [ClubController::class, 'show'])->name('api.clubs.show');

        Route::get('sessions', [SessionController::class, 'index'])->name('api.sessions.index');
        Route::get('sessions/{session}', [SessionController::class, 'show'])->name('api.sessions.show');
        Route::get('sessions/{session}/rounds', [RoundController::class, 'index'])->name('api.sessions.rounds');
        Route::get('sessions/{session}/leaderboard', [LeaderboardController::class, 'index'])->name('api.sessions.leaderboard');
    });
});
