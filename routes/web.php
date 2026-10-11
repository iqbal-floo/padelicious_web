<?php

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
