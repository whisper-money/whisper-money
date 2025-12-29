<?php

namespace App\Providers;

use App\Events\TransactionCreated;
use App\Events\TransactionDeleted;
use App\Events\TransactionUpdated;
use App\Http\Responses\RegisterResponse;
use App\Listeners\AssignTransactionToBudget;
use App\Listeners\UnassignTransactionFromBudget;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
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
        Event::listen(TransactionCreated::class, AssignTransactionToBudget::class);
        Event::listen(TransactionUpdated::class, AssignTransactionToBudget::class);
        Event::listen(TransactionDeleted::class, UnassignTransactionFromBudget::class);

        RateLimiter::for('emails', function (object $job): Limit {
            return Limit::perSecond(1);
        });

        Feature::define('budgets', fn (User $user) => false);
    }
}
