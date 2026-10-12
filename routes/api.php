<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClubController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\LiveScoreController;
use App\Http\Controllers\Api\MatchTypeController;
use App\Http\Controllers\Api\OrderOfPlayController;
use App\Http\Controllers\Api\ParticipantController;
use App\Http\Controllers\Api\PlayerController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReclubController;
use App\Http\Controllers\Api\RoundController;
use App\Http\Controllers\Api\ScoreController;
use App\Http\Controllers\Api\SessionController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (v1)
|--------------------------------------------------------------------------
| Consumed by the Padelicious Flutter client.
|
| Writes that change a session require the caller to be its host or a
| participant with the admin role (SessionPolicy::manage).
*/

// Local/testing only: lets the integration suite reset the auth throttle
// so it exercises auth rather than the rate limiter.
if (! app()->isProduction()) {
    Route::post('test/clear-rate-limit', function () {
        // The throttle middleware keys off the cache store, so flushing it
        // is what actually resets the counters.
        Cache::flush();

        return response()->json(['cleared' => true]);
    })->name('api.test.clear-rate-limit');
}

Route::prefix('v1')->group(function () {
    // Public live score: a spectator needs only the match uuid. Read-only.
    Route::get('live/matches/{match}', [LiveScoreController::class, 'show'])
        ->name('api.live.match');
    Route::get('live/matches/{match}/stream', [LiveScoreController::class, 'stream'])
        ->name('api.live.match.stream');

    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:6,1')
        ->name('api.auth.login');

    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:6,1')
        ->name('api.auth.register');

    Route::post('auth/google', [AuthController::class, 'google'])
        ->middleware('throttle:10,1')
        ->name('api.auth.google');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('api.auth.me');
        Route::patch('profile', [ProfileController::class, 'update'])->name('api.profile.update');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');

        Route::get('match-types', [MatchTypeController::class, 'index'])->name('api.match-types.index');

        Route::get('players', [PlayerController::class, 'index'])->name('api.players.index');
        Route::post('players', [PlayerController::class, 'store'])->name('api.players.store');

        // Reclub meet roster import (reads reclub.co/m/{code})
        Route::post('reclub/import', [ReclubController::class, 'import'])
            ->middleware('throttle:20,1')
            ->name('api.reclub.import');
        Route::get('players/{player}', [PlayerController::class, 'show'])->name('api.players.show');

        Route::get('clubs', [ClubController::class, 'index'])->name('api.clubs.index');
        Route::get('clubs/{club}', [ClubController::class, 'show'])->name('api.clubs.show');

        // Sessions
        Route::get('sessions', [SessionController::class, 'index'])->name('api.sessions.index');
        Route::post('sessions', [SessionController::class, 'store'])->name('api.sessions.store');
        Route::get('sessions/{session}', [SessionController::class, 'show'])->name('api.sessions.show');
        Route::post('sessions/{session}/complete', [SessionController::class, 'complete'])->name('api.sessions.complete');
        Route::delete('sessions/{session}', [SessionController::class, 'cancel'])->name('api.sessions.cancel');

        // Roster
        Route::post('sessions/{session}/participants', [ParticipantController::class, 'store'])->name('api.participants.store');
        Route::delete('sessions/{session}/participants/{participant}', [ParticipantController::class, 'destroy'])->name('api.participants.destroy');
        Route::patch('sessions/{session}/participants/{participant}', [ParticipantController::class, 'update'])->name('api.participants.update');
        Route::post('sessions/{session}/join', [ParticipantController::class, 'join'])->name('api.sessions.join');

        // Rounds & matches
        Route::get('sessions/{session}/rounds', [RoundController::class, 'index'])->name('api.sessions.rounds');
        Route::post('sessions/{session}/rounds', [RoundController::class, 'store'])->name('api.rounds.store');
        Route::post('rounds/{round}/complete', [RoundController::class, 'complete'])->name('api.rounds.complete');
        Route::post('rounds/{round}/reopen', [RoundController::class, 'reopen'])->name('api.rounds.reopen');

        // Scoring — the match_points event log
        Route::post('matches/{match}/points', [ScoreController::class, 'store'])->name('api.matches.points.store');
        Route::delete('matches/{match}/points', [ScoreController::class, 'destroy'])->name('api.matches.points.undo');
        Route::post('matches/{match}/reset', [ScoreController::class, 'reset'])->name('api.matches.reset');

        Route::get('sessions/{session}/leaderboard', [LeaderboardController::class, 'index'])->name('api.sessions.leaderboard');
        Route::get('sessions/{session}/order-of-play', [OrderOfPlayController::class, 'show'])->name('api.sessions.order-of-play');
    });
});
