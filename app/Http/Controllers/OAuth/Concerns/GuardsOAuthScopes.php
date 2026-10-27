<?php

namespace App\Http\Controllers\OAuth\Concerns;

use Laravel\Passport\Exceptions\OAuthServerException;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException as LeagueException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;

/**
 * The scope rules both halves of the consent flow (showing the screen and
 * approving it) enforce on top of Passport's own validation.
 */
trait GuardsOAuthScopes
{
    /**
     * @return list<string>
     */
    protected function requestedScopeIds(AuthorizationRequestInterface $authRequest): array
    {
        return array_values(array_map(
            fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(),
            $authRequest->getScopes(),
        ));
    }

    /**
     * Send the client back to its (already validated) redirect URI with an
     * OAuth error (`invalid_scope`, `access_denied`), carrying its state.
     */
    protected function failAuthorization(AuthorizationRequestInterface $authRequest, string $errorType, string $description): never
    {
        throw new OAuthServerException(new LeagueException(
            $description,
            0,
            $errorType,
            400,
            null,
            $this->errorRedirectUri($authRequest),
        ));
    }

    private function errorRedirectUri(AuthorizationRequestInterface $authRequest): ?string
    {
        $redirectUri = $authRequest->getRedirectUri() ?? ((array) $authRequest->getClient()->getRedirectUri())[0] ?? null;

        if ($redirectUri === null || $authRequest->getState() === null) {
            return $redirectUri;
        }

        return $redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').http_build_query(['state' => $authRequest->getState()]);
    }
}
