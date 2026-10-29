<?php

namespace App\Providers;

use App\Http\ApiV1\Documentation\DocumentConventions;
use App\Http\ApiV1\Documentation\OperationConventions;
use Dedoc\Scramble\Scramble;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Points Scramble at the public API: only the routes of the `api-v1`
 * middleware group, with the shared conventions documented once (see
 * OperationConventions) instead of on every controller. Scramble's own
 * routes, its Stoplight UI and spec under `/docs/api`, are never registered;
 * the spec is served by OpenApiController.
 *
 * The server URL is pinned to APP_URL here, before Scramble reads its config
 * while booting: a relative one would be resolved against the request host,
 * which a forwarded header can forge, and the spec is publicly cacheable.
 */
class ApiDocumentationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Scramble::ignoreDefaultRoutes();

        config(['scramble.servers' => [
            'Whisper Money' => rtrim((string) config('app.url'), '/').'/'.config('scramble.api_path'),
        ]]);
    }

    public function boot(): void
    {
        Scramble::configure()
            ->routes(fn (Route $route): bool => in_array('api-v1', $route->middleware(), true))
            ->withOperationTransformers(OperationConventions::class)
            ->withDocumentTransformers(DocumentConventions::class);
    }
}
