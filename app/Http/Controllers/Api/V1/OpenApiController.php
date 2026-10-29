<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller as BaseController;
use Dedoc\Scramble\CacheableGenerator;
use Dedoc\Scramble\Scramble;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/openapi.json`: the OpenAPI spec of the public API, open to
 * anyone. Registered outside the `api-v1` group (see bootstrap/app.php), so
 * it needs no token and counts against no one's rate limit (it has a per-IP
 * one of its own).
 *
 * Served from the cache `php artisan scramble:cache` fills on deploy, and
 * generated on the fly when that cache is empty.
 */
class OpenApiController extends BaseController
{
    public function __invoke(CacheableGenerator $generator): JsonResponse
    {
        return response()
            ->json($generator(Scramble::getGeneratorConfig(Scramble::DEFAULT_API)), options: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            ->setPublic()
            ->setMaxAge(3600);
    }
}
