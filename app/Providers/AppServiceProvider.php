<?php

namespace App\Providers;

use App\Models\MatchGame;
use App\Models\Session;
use App\Policies\SessionPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Session::class, SessionPolicy::class);

        // The model is MatchGame (PHP reserves `match`), so implicit binding
        // would expect {matchGame}. Bind {match} explicitly to keep the
        // route readable as /matches/{match}.
        Route::model('match', MatchGame::class);
    }
}
