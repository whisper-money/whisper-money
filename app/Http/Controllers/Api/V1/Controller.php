<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Account;
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
        return app(SpaceResolver::class)->forApiRequest($request);
    }

    /**
     * The route's account, looked up in the request's space: an account in
     * another space, or someone else's, is a 404 like one that does not exist.
     */
    protected function account(Request $request, string $id): Account
    {
        return app(SpaceResolver::class)->accountOrFail($this->space($request), $id);
    }
}
