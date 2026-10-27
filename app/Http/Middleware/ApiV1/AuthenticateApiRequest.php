<?php

namespace App\Http\Middleware\ApiV1;

use App\Enums\ApiCredential;
use App\Http\ApiV1\ApiV1Exception;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates `/api/v1` with a bearer token only:
 *
 * - a Sanctum personal access token carrying `api:read`, plus `api:write`
 *   for the write methods;
 * - a Passport OAuth access token carrying the `api:use` scope (read and
 *   write, approved by the user on the consent screen).
 *
 * Sanctum and Passport both fall back to a first-party session or cookie and
 * hand back a TransientToken that passes every ability check. Those are
 * refused here, as are MCP credentials (`mcp:*` abilities, `mcp:use` scope)
 * and wildcard (`*`) tokens, so neither surface's tokens open the other.
 *
 * Implements AuthenticatesRequests so the router sorts it ahead of the
 * throttle, which keys its bucket on the authenticated user.
 */
class AuthenticateApiRequest implements AuthenticatesRequests
{
    private const string ReadAbility = 'api:read';

    private const string WriteAbility = 'api:write';

    private const string OAuthScope = 'api:use';

    private const string McpOAuthScope = 'mcp:use';

    /** @var list<string> */
    private const array WriteMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private AuthFactory $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->auth->guard('sanctum')->user();

        if ($user instanceof User) {
            $this->authorizePersonalAccessToken($request, $this->bearer($user, PersonalAccessToken::class));
            $this->authenticateAs($request, 'sanctum', ApiCredential::PersonalAccessToken);

            return $next($request);
        }

        $user = $this->auth->guard('api')->user();

        if ($user instanceof User) {
            $token = $this->bearer($user, AccessToken::class);
            $this->authorizeOAuthToken($token);
            $this->authenticateAs($request, 'api', ApiCredential::OAuth, $token->oauth_client_id);

            return $next($request);
        }

        throw ApiV1Exception::unauthenticated();
    }

    /**
     * The bearer token the guard authenticated the user with. Anything else
     * (a TransientToken from a session or cookie) is refused.
     *
     * @template TToken of object
     *
     * @param  class-string<TToken>  $type
     * @return TToken
     */
    private function bearer(User $user, string $type): object
    {
        $token = $user->currentAccessToken();

        if (! $token instanceof $type) {
            throw ApiV1Exception::unauthenticated('Authenticate with a bearer token.');
        }

        return $token;
    }

    private function authorizePersonalAccessToken(Request $request, PersonalAccessToken $token): void
    {
        $abilities = $token->abilities ?? [];

        if (! in_array(self::ReadAbility, $abilities, true)) {
            throw ApiV1Exception::insufficientScope('This token cannot be used with the API. Create an API token in Settings → API.');
        }

        if (in_array($request->method(), self::WriteMethods, true) && ! in_array(self::WriteAbility, $abilities, true)) {
            throw ApiV1Exception::insufficientScope('This token is read-only. Create a read & write token to make changes.');
        }
    }

    private function authorizeOAuthToken(AccessToken $token): void
    {
        $scopes = $token->oauth_scopes ?? [];

        if (! in_array(self::OAuthScope, $scopes, true) || in_array(self::McpOAuthScope, $scopes, true)) {
            throw ApiV1Exception::insufficientScope('This access token was not granted the api:use scope.');
        }
    }

    private function authenticateAs(Request $request, string $guard, ApiCredential $credential, ?string $oauthClientId = null): void
    {
        $this->auth->shouldUse($guard);

        $request->attributes->set(ApiCredential::class, $credential);
        $request->attributes->set('oauth_client_id', $oauthClientId);
    }
}
