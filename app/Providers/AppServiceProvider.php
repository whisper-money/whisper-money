<?php

namespace App\Providers;

use App\Http\Responses\RegisterResponse;
use App\Models\Transaction;
use App\Models\User;
use App\Observers\TransactionObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Pennant\Feature;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Transaction::observe(TransactionObserver::class);

        RateLimiter::for('emails', function (object $job): Limit {
            return Limit::perSecond(1);
        });

        Feature::define('budgets', fn (User $user) => false);
    }
}
