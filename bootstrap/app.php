<?php

use App\Http\ApiV1\ErrorRenderer;
use App\Http\Middleware\ApiV1\AuthenticateApiRequest;
use App\Http\Middleware\ApiV1\EnsureApiAccess;
use App\Http\Middleware\ApiV1\HandleIdempotencyKey;
use App\Http\Middleware\ApiV1\RecordApiRequest;
use App\Http\Middleware\ApiV1\ThrottleApiRequestsByIp;
use App\Http\Middleware\AssignPriceExperimentArm;
use App\Http\Middleware\BlockDemoAccountActions;
use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\DenyFraming;
use App\Http\Middleware\EnsureCustomDashboards;
use App\Http\Middleware\EnsureOnboardingComplete;
use App\Http\Middleware\EnsureOpenBankingConfigured;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsSubscribed;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetSentryUser;
use App\Http\Middleware\TrackLastActiveAt;
use App\Jobs\SyncBankingConnectionJob;
use App\Services\AuthEntryPointService;
use App\Support\QueueWorkerLoop;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use League\OAuth2\Server\Exception\OAuthServerException;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The public API: stateless, bearer-only, outside the `web` group the
        // internal `/api/*` routes run under.
        then: fn () => Route::middleware('api-v1')
            ->prefix('api/v1')
            ->name('api.v1.')
            ->group(base_path('routes/api-v1.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (Request $request) => app(AuthEntryPointService::class)->guestRedirectRoute($request));

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'chart-color-scheme']);

        $trustedProxies = env('TRUSTED_PROXIES');

        $middleware->trustProxies(
            at: is_string($trustedProxies) ? array_map('trim', explode(',', $trustedProxies)) : '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
        );

        $middleware->web(append: [
            // First, so the nonce exists before any view renders.
            ContentSecurityPolicy::class,
            HandleAppearance::class,
            SetLocale::class,
            // Before HandleInertiaRequests: it draws the visitor's price arm onto
            // the request, which the shared pricing prop then reads.
            AssignPriceExperimentArm::class,
            SetSentryUser::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            TrackLastActiveAt::class,
            BlockDemoAccountActions::class.':auto',
        ]);

        // The per-IP throttle caps anonymous floods before any token lookup.
        // Then authenticate, so every later step (metric, per-user throttle,
        // plan gate, per-credential idempotency cache) knows the caller. The per-user
        // throttle sits ahead of the plan gate, so a refused account is capped
        // too, and inside the metric, so its 429s are recorded.
        $middleware->group('api-v1', [
            ThrottleApiRequestsByIp::class,
            AuthenticateApiRequest::class,
            RecordApiRequest::class,
            'throttle:api-v1',
            EnsureApiAccess::class,
            HandleIdempotencyKey::class,
            SubstituteBindings::class,
        ]);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'subscribed' => EnsureUserIsSubscribed::class,
            'onboarded' => EnsureOnboardingComplete::class,
            'open-banking' => EnsureOpenBankingConfigured::class,
            'block-demo' => BlockDemoAccountActions::class,
            'deny-framing' => DenyFraming::class,
            'custom-dashboards' => EnsureCustomDashboards::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);

        $exceptions->dontReportWhen(fn (Throwable $e): bool => $e instanceof MaxAttemptsExceededException
            && $e->job?->resolveName() === SyncBankingConnectionJob::class);

        $exceptions->dontReportWhen(fn (Throwable $e): bool => $e instanceof OAuthServerException
            && $e->getHttpStatusCode() < 500);

        // A deploy takes MySQL and Redis away underneath the queue workers, and
        // every worker that is mid-poll reports it: one restart, five identical
        // exceptions, and nothing to do about any of them. Only the poll loop is
        // covered — a connection lost inside a job, or in a request, still reports.
        $exceptions->dontReportWhen(fn (Throwable $e): bool => app(QueueWorkerLoop::class)->isLostConnectionWhilePolling($e));

        // `/api/v1` always answers JSON, through a single renderer (see
        // ErrorRenderer); validation keeps Laravel's standard 422 body.
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => ErrorRenderer::handles($request) || $request->expectsJson());

        $exceptions->render(fn (Throwable $e, Request $request) => ErrorRenderer::handles($request)
            ? app(ErrorRenderer::class)->render($e)
            : null);
    })->create();
