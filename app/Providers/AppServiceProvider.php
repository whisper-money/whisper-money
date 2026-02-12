<?php

namespace App\Providers;

use App\Contracts\BankingProviderInterface;
use App\Events\TransactionCreated;
use App\Events\TransactionDeleted;
use App\Events\TransactionUpdated;
use App\Http\Responses\RegisterResponse;
use App\Listeners\ApplyAutomationRules;
use App\Listeners\AssignTransactionToBudget;
use App\Listeners\UnassignTransactionFromBudget;
use App\Models\User;
use App\Services\Banking\EnableBankingProvider;
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

        $this->app->bind(BankingProviderInterface::class, function ($app) {
            return new EnableBankingProvider(
                config('services.enablebanking.app_id'),
                base_path(config('services.enablebanking.private_key_path')),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(TransactionCreated::class, ApplyAutomationRules::class);
        Event::listen(TransactionCreated::class, AssignTransactionToBudget::class);
        Event::listen(TransactionUpdated::class, AssignTransactionToBudget::class);
        Event::listen(TransactionDeleted::class, UnassignTransactionFromBudget::class);

        RateLimiter::for('emails', function (object $job): Limit {
            return Limit::perSecond(30);
        });

        Feature::define('plaintext-transactions', fn (User $user) => false);
        Feature::define('open-banking', fn (User $user) => false);
        Feature::define('account-mapping', fn (User $user) => false);
    }
}
