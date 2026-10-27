<?php

namespace App\Providers;

use App\Contracts\BankingProviderInterface;
use App\Enums\OAuthScope;
use App\Http\Controllers\OAuth\ApproveAuthorizationController;
use App\Http\Controllers\OAuth\AuthorizationController;
use App\Http\Middleware\ApiV1\ThrottleApiRequestsByIp;
use App\Http\Responses\RegisterResponse;
use App\Mcp\OAuthRegistrar;
use App\Services\Ai\Contracts\RuleSuggestionGenerator;
use App\Services\Ai\Contracts\TransactionMatcher;
use App\Services\Ai\LaravelAiRuleSuggestionGenerator;
use App\Services\Ai\UncategorizedTransactionMatcher;
use App\Services\Banking\EnableBankingProvider;
use App\Services\Discord\DiscordWebhook;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController as PassportApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\AuthorizationController as PassportAuthorizationController;
use Laravel\Passport\Passport;
use Laravel\Passport\Scope;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Cashier::keepPastDueSubscriptionsActive();

        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);

        $this->app->bind(BankingProviderInterface::class, function () {
            return new EnableBankingProvider(
                // Cast rather than refuse: open banking is optional, and the
                // container builds this for paths that never call it — closing
                // a Coinbase connection, listing its accounts. An unconfigured
                // install used to get a TypeError out of the container instead.
                // The routes that do call it are gated by `open-banking`.
                (string) config('services.enablebanking.app_id'),
                base_path((string) config('services.enablebanking.private_key_path')),
            );
        });

        $this->app->bind(DiscordWebhook::class, function () {
            return new DiscordWebhook(config('services.discord.webhook_url'));
        });

        // The OAuth server serves two surfaces (MCP, API): these add the scope
        // rules and discovery metadata on top of Passport's and laravel/mcp's.
        $this->app->singleton(Registrar::class, fn (): Registrar => new OAuthRegistrar);
        $this->app->bind(PassportAuthorizationController::class, AuthorizationController::class);
        $this->app->when(AuthorizationController::class)
            ->needs(StatefulGuard::class)
            ->give(fn () => Auth::guard(config('passport.guard')));
        $this->app->bind(PassportApproveAuthorizationController::class, ApproveAuthorizationController::class);

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

        // SES allows this account 10 sends per second (and 50,000 a day), and
        // throttles anything above it. Every mailable and job on the `emails`
        // queue passes through here, so a bulk campaign shares this bucket with
        // transactional mail: bank failures, budget alerts, the drip series.
        // Raising it past 10 does not send faster, it just makes SES start
        // rejecting. At 10/s a 4,600-email campaign clears in under 8 minutes.
        RateLimiter::for('emails', function (object $job): Limit {
            return Limit::perSecond(10);
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

        // The public API (`/api/v1`): 60 requests a minute per user, answered
        // with X-RateLimit-Limit / X-RateLimit-Remaining (and Retry-After once
        // exhausted). Authentication runs first, so there is always a user.
        RateLimiter::for('api-v1', function (Request $request): Limit {
            return Limit::perMinute(60)->by((string) $request->user()?->getAuthIdentifier());
        });

        // Ahead of authentication, every `/api/v1` request is also counted
        // per IP, so bogus tokens cannot be sprayed unchecked. 120 a minute is
        // two users' full budget: room for a household or an office behind
        // one NAT, still a hard cap for a script guessing tokens.
        RateLimiter::for(ThrottleApiRequestsByIp::Limiter, function (Request $request): Limit {
            return Limit::perMinute(120)->by((string) $request->ip());
        });

        // AI column-mapping suggestions are asked once per imported file, so 20
        // a minute per user is plenty. A named limiter keeps its own bucket: a
        // plain `throttle:20,1` would share (and double-count) the key of the
        // `throttle:300,1` around every API route.
        RateLimiter::for('import-column-suggestions', function (Request $request): Limit {
            return Limit::perMinute(20)->by((string) $request->user()?->getAuthIdentifier());
        });

        // A CSV export scans the user's whole filtered history, so ten a minute
        // is generous for a person and a ceiling for a script. Named for the
        // same reason as above: its own bucket, not the API routes' counter.
        RateLimiter::for('transaction-export', function (Request $request): Limit {
            return Limit::perMinute(10)->by((string) $request->user()?->getAuthIdentifier());
        });

        // `mcp:use` (MCP connectors) and `api:use` (third-party API apps). An
        // authorization request naming no scope gets `mcp:use`, so a connector
        // that never asked for one keeps reaching `/mcp/oauth`.
        OAuthScope::register();
        Passport::defaultScopes([OAuthScope::Mcp->value]);

        // Render the OAuth consent screen (an MCP connector or an API app) with
        // our own on-brand Blade view, worded for the scope it asks for.
        Passport::authorizationView(function (array $parameters): HttpResponse {
            $scope = OAuthScope::fromRequested(array_map(fn (Scope $scope): string => $scope->id, $parameters['scopes']));

            return response()->view('oauth.authorize', [
                ...$parameters,
                'surface' => $scope,
                'refusal' => $scope->approvalRefusalFor($parameters['user']),
                'redirectHost' => parse_url((string) ($parameters['request']->query('redirect_uri') ?? $parameters['client']->redirect_uris[0] ?? ''), PHP_URL_HOST),
            ]);
        });
    }
}
