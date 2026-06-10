<?php

use App\Http\Middleware\BlockDemoAccountActions;
use App\Http\Middleware\EnsureOnboardingComplete;
use App\Http\Middleware\EnsureUserIsSubscribed;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetSentryUser;
use App\Http\Middleware\TrackLastActiveAt;
use App\Jobs\SyncBankingConnectionJob;
use App\Services\AuthEntryPointService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Queue\MaxAttemptsExceededException;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (Request $request) => app(AuthEntryPointService::class)->guestRedirectRoute($request));

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'chart-color-scheme']);

        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
        );

        $middleware->web(append: [
            HandleAppearance::class,
            SetLocale::class,
            SetSentryUser::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            TrackLastActiveAt::class,
            BlockDemoAccountActions::class.':auto',
        ]);

        $middleware->alias([
            'subscribed' => EnsureUserIsSubscribed::class,
            'onboarded' => EnsureOnboardingComplete::class,
            'block-demo' => BlockDemoAccountActions::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);

        $exceptions->dontReportWhen(fn (Throwable $e): bool => $e instanceof MaxAttemptsExceededException
            && $e->job?->resolveName() === SyncBankingConnectionJob::class);
    })->create();
