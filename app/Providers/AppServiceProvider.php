<?php

namespace App\Providers;

use App\Contracts\BankingProviderInterface;
use App\Events\TransactionCreated;
use App\Events\TransactionDeleted;
use App\Events\TransactionUpdated;
use App\Http\Responses\RegisterResponse;
use App\Listeners\ApplyAutomationRules;
use App\Listeners\AssignTransactionToBudget;
use App\Listeners\PostStripeEventToDiscord;
use App\Listeners\UnassignTransactionFromBudget;
use App\Listeners\UpdateLastLoggedInAt;
use App\Services\Ai\Contracts\RuleSuggestionGenerator;
use App\Services\Ai\Contracts\TransactionMatcher;
use App\Services\Ai\LaravelAiRuleSuggestionGenerator;
use App\Services\Ai\UncategorizedTransactionMatcher;
use App\Services\Banking\EnableBankingProvider;
use App\Services\Discord\DiscordWebhook;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Cashier::keepPastDueSubscriptionsActive();

        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);

        $this->app->bind(BankingProviderInterface::class, function ($app) {
            return new EnableBankingProvider(
                config('services.enablebanking.app_id'),
                base_path(config('services.enablebanking.private_key_path')),
            );
        });

        $this->app->bind(DiscordWebhook::class, function () {
            return new DiscordWebhook(config('services.discord.webhook_url'));
        });

        $this->app->bind(TransactionMatcher::class, UncategorizedTransactionMatcher::class);
        $this->app->bind(RuleSuggestionGenerator::class, LaravelAiRuleSuggestionGenerator::class);
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
        Event::listen(WebhookReceived::class, PostStripeEventToDiscord::class);
        Event::listen(Login::class, UpdateLastLoggedInAt::class);

        RateLimiter::for('emails', function (object $job): Limit {
            return Limit::perSecond(30);
        });
    }
}
