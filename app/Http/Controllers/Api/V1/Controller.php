<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Space;
use App\Models\User;
use App\Services\SpaceResolver;
use Illuminate\Http\Request;

/**
 * Base for the public `/api/v1` controllers. The authenticated user is always
 * a User here: AuthenticateApiRequest runs on the whole group.
 */
abstract class Controller extends BaseController
{
    protected function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    /**
     * The space the request operates on: the optional `space` query or body
     * parameter, or the user's personal space. An inaccessible id is a 422.
     */
    protected function space(Request $request): Space
    {
        return app(SpaceResolver::class)->resolve(
            $this->user($request),
            $request->string('space')->toString(),
            'List the valid ids with GET /api/v1/spaces.',
        );
    }
}
