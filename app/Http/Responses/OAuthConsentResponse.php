<?php

namespace App\Http\Responses;

use App\Enums\OAuthScope;
use App\Models\User;
use App\Support\RedirectTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Passport\Client;
use Laravel\Passport\Scope;

/**
 * The OAuth consent screen (an MCP connector or an API app), worded for the
 * scope requested, naming where approving sends the user and, for a request
 * the user may not approve, why instead of the approve button.
 */
class OAuthConsentResponse
{
    /**
     * @param  array{client: Client, user: User, scopes: Scope[], request: Request, authToken: string}  $parameters
     */
    public function __invoke(array $parameters): Response
    {
        $scope = OAuthScope::fromRequested(array_map(fn (Scope $scope): string => $scope->id, $parameters['scopes']));
        $redirectUri = (string) ($parameters['request']->query('redirect_uri') ?? $parameters['client']->redirect_uris[0] ?? '');

        return response()->view('oauth.authorize', [
            ...$parameters,
            'surface' => $scope,
            'refusal' => $scope->approvalRefusalFor($parameters['user']),
            'redirectTarget' => $redirectUri === '' ? null : RedirectTarget::display($redirectUri),
        ]);
    }
}
