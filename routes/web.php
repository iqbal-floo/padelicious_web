<?php

use App\Http\Controllers\OrderOfPlayController;
use App\Models\MatchGame;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/**
 * Public spectator scoreboard. Shareable by match uuid; read-only.
 */
Route::get('/live/{match}', function (MatchGame $match) {
    return view('live', ['matchId' => $match->id]);
})->name('live.match');

/**
 * Printable order of play (HTML, or ?format=pdf). Shareable by session uuid.
 */
Route::get('/sessions/{session}/order-of-play', [OrderOfPlayController::class, 'show'])
    ->name('sessions.order-of-play');
