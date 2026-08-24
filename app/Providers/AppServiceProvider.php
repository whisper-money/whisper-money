<?php

namespace App\Providers;

use App\Contracts\BankingProviderInterface;
use App\Http\Responses\RegisterResponse;
use App\Services\Ai\Contracts\RuleSuggestionGenerator;
use App\Services\Ai\Contracts\TransactionMatcher;
use App\Services\Ai\LaravelAiRuleSuggestionGenerator;
use App\Services\Ai\UncategorizedTransactionMatcher;
use App\Services\Banking\EnableBankingProvider;
use App\Services\Discord\DiscordWebhook;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Passport\Passport;

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
        // Event listeners are registered automatically via Laravel's event
        // discovery of the App\Listeners directory (matched by their handle()
        // type-hint). Do not also register them explicitly here — doing both
        // registers every listener twice, so each queued listener is dispatched
        // twice per event.
        RateLimiter::for('emails', function (object $job): Limit {
            return Limit::perSecond(30);
        });

        // MCP requests are throttled per authenticated user. The shared accounts
        // are driven by several people at once (a press round is the point of
        // the press account), so one bucket of 60 would have them throttling
        // each other mid-conversation.
        RateLimiter::for('mcp', function (Request $request): Limit {
            $user = $request->user();

            if ($user === null) {
                return Limit::perMinute(60)->by($request->ip());
            }

            return Limit::perMinute($user->isRestrictedSharedAccount() ? 600 : 60)
                ->by((string) $user->getAuthIdentifier());
        });

        // Render the OAuth consent screen (Claude Desktop / ChatGPT connecting
        // to the MCP server) with our own on-brand Blade view.
        Passport::authorizationView(fn (array $parameters) => response()->view('mcp.authorize', $parameters));
    }
}
