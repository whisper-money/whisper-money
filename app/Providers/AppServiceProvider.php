<?php

namespace App\Providers;

use App\Contracts\BankingProviderInterface;
use App\Enums\OAuthScope;
use App\Http\Controllers\OAuth\ApproveAuthorizationController;
use App\Http\Controllers\OAuth\AuthorizationController;
use App\Http\Controllers\OAuth\RegisterController;
use App\Http\Middleware\ApiV1\ThrottleApiRequestsByIp;
use App\Http\Responses\OAuthConsentResponse;
use App\Http\Responses\RegisterResponse;
use App\Mcp\OAuthRegistrar;
use App\Models\User;
use App\Models\WidgetDefinition;
use App\Services\Ai\Contracts\RuleSuggestionGenerator;
use App\Services\Ai\Contracts\TransactionMatcher;
use App\Services\Ai\LaravelAiRuleSuggestionGenerator;
use App\Services\Ai\UncategorizedTransactionMatcher;
use App\Services\Banking\EnableBankingProvider;
use App\Services\Banking\EnableBankingPsuContext;
use App\Services\Discord\DiscordWebhook;
use App\Services\Widgets\Gateway\WidgetDataGateway;
use App\Support\OAuthScopeRepository;
use App\Support\QueueWorkerLoop;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Bridge\ScopeRepository;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController as PassportApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\AuthorizationController as PassportAuthorizationController;
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
        $this->app->scoped(EnableBankingPsuContext::class);

        $this->app->bind(BankingProviderInterface::class, function ($app) {
            return new EnableBankingProvider(
                // Cast rather than refuse: open banking is optional, and the
                // container builds this for paths that never call it — closing
                // a Coinbase connection, listing its accounts. An unconfigured
                // install used to get a TypeError out of the container instead.
                // The routes that do call it are gated by `open-banking`.
                (string) config('services.enablebanking.app_id'),
                base_path((string) config('services.enablebanking.private_key_path')),
                $app->make(EnableBankingPsuContext::class),
            );
        });

        $this->app->bind(DiscordWebhook::class, function () {
            return new DiscordWebhook(config('services.discord.webhook_url'));
        });

        // A singleton because the reporting rule in bootstrap/app.php and the
        // queue events below have to be looking at the same flags.
        $this->app->singleton(QueueWorkerLoop::class);

        // The OAuth server serves two surfaces (MCP, API): these add the scope
        // rules and discovery metadata on top of Passport's and laravel/mcp's.
        $this->app->singleton(Registrar::class, fn (): Registrar => new OAuthRegistrar);
        $this->app->bind(PassportAuthorizationController::class, AuthorizationController::class);
        $this->app->when(AuthorizationController::class)
            ->needs(StatefulGuard::class)
            ->give(fn () => Auth::guard(config('passport.guard')));
        $this->app->bind(PassportApproveAuthorizationController::class, ApproveAuthorizationController::class);
        $this->app->bind(OAuthRegisterController::class, RegisterController::class);
        $this->app->bind(ScopeRepository::class, OAuthScopeRepository::class);

        // The device-code grant approves through Passport's own controllers,
        // which skip the scope rules above. No client uses it; keep it off.
        Passport::$deviceCodeGrantEnabled = false;

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

        // Closures rather than App\Listeners classes on purpose: those are
        // auto-discovered, so a class here would be registered twice. These only
        // flip a flag the exception reporting rule reads, so they must not queue.
        // Blocks rather than arrow functions because `Looping` is dispatched with
        // `until()`: a listener that returned anything but null would stop the
        // worker from picking up its next job.
        Event::listen(Looping::class, function (): void {
            $this->app->make(QueueWorkerLoop::class)->enterLoop();
        });
        Event::listen(JobProcessing::class, function (): void {
            $this->app->make(QueueWorkerLoop::class)->enterJob();
        });
        // Only the success event, never JobExceptionOccurred: the worker dispatches
        // that one *before* it reports, so clearing the flag there would silence
        // exactly the lost connections that happen inside a job. A job that threw
        // is cleared by the next turn's `Looping` instead.
        Event::listen(JobProcessed::class, function (): void {
            $this->app->make(QueueWorkerLoop::class)->leaveJob();
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

        // Dynamic client registration is open to anyone and writes a row. A
        // connector registers when it is connected, so ten a minute per IP
        // never reaches one and still caps a script filling `oauth_clients`.
        RateLimiter::for('oauth-register', function (Request $request): Limit {
            return Limit::perMinute(10)->by((string) $request->ip());
        });

        // The widget data gateway counts every API call it makes for a
        // dashboard's widgets, apart from the public API's 60 a minute so
        // opening a dashboard does not spend the user's integrations' budget.
        // It takes the User, not a Request: it counts internal calls, so it
        // is never a route's `throttle:` middleware.
        RateLimiter::for(WidgetDataGateway::Limiter, function (User $user): Limit {
            return Limit::perMinute((int) config('widgets.gateway.calls_per_minute'))->by((string) $user->getAuthIdentifier());
        });

        // The runtime errors user widgets report from the dashboard. A widget
        // that fails on every render would otherwise write on every render;
        // only the latest error is kept, so a few a minute lose nothing. Per
        // widget, so one failing in a loop does not silence the others.
        RateLimiter::for('widget-errors', function (Request $request): Limit {
            $widget = $request->route('widget');

            return Limit::perMinute((int) config('widgets.errors.reports_per_minute'))
                ->by($request->user()?->getAuthIdentifier().'|'.($widget instanceof WidgetDefinition ? $widget->id : $widget));
        });

        // Going back to an earlier revision of a user widget, from the
        // dashboard. Each one compiles the widget again, synchronously, so a
        // few a minute per user is plenty for a person and a ceiling for a
        // loop.
        RateLimiter::for('widget-reverts', function (Request $request): Limit {
            return Limit::perMinute(10)->by((string) $request->user()?->getAuthIdentifier());
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

        // One request per batch of day totals a list draws, so thirty a minute
        // is plenty, and each can reach the rates provider. Named for the same
        // reason as above: its own bucket, not the API routes' counter.
        RateLimiter::for('converted-amounts', function (Request $request): Limit {
            return Limit::perMinute(30)->by((string) $request->user()?->getAuthIdentifier());
        });

        // `mcp:use` (MCP connectors) and `api:use` (third-party API apps). An
        // authorization request naming no scope gets `mcp:use`, so a connector
        // that never asked for one keeps reaching `/mcp/oauth`.
        OAuthScope::register();
        Passport::defaultScopes([OAuthScope::Mcp->value]);

        // Render the OAuth consent screen (an MCP connector or an API app) with
        // our own on-brand Blade view, worded for the scope it asks for.
        Passport::authorizationView(fn (array $parameters) => app(OAuthConsentResponse::class)($parameters));
    }
}
