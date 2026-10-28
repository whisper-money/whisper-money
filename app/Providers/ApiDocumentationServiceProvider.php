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
 */
class ApiDocumentationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Scramble::ignoreDefaultRoutes();
    }

    public function boot(): void
    {
        Scramble::configure()
            ->routes(fn (Route $route): bool => in_array('api-v1', $route->middleware(), true))
            ->withOperationTransformers(OperationConventions::class)
            ->withDocumentTransformers(DocumentConventions::class);
    }
}
