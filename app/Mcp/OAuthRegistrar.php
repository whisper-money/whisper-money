<?php

namespace App\Mcp;

use App\Enums\OAuthScope;
use Laravel\Mcp\Server\Registrar;

/**
 * The MCP registrar, taught that the authorization server it exposes (DCR,
 * discovery) also serves the public API. Bound in place of the package's
 * Registrar, so the routes `Mcp::oauthRoutes()` registers call these methods.
 *
 * - The authorization server metadata lists every scope, so a third-party
 *   API app registering through the same DCR endpoint finds `api:use`.
 * - The protected resource metadata stays per resource: `/api/v1` advertises
 *   `api:use` and everything else (`/mcp/oauth`) keeps `mcp:use` alone, which
 *   is what the Claude and ChatGPT connectors read and request today.
 */
class OAuthRegistrar extends Registrar
{
    /**
     * Called by `Mcp::oauthRoutes()`. Registers every scope, not just
     * `mcp:use`, merging like the parent does.
     *
     * @return array<string, string>
     */
    public static function ensureMcpScope(): array
    {
        return OAuthScope::register();
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    protected static function authorizationServerMetadata(string $oauthPrefix): array
    {
        return [
            ...parent::authorizationServerMetadata($oauthPrefix),
            'scopes_supported' => OAuthScope::values(),
        ];
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    protected static function protectedResourceMetadata(string $path): array
    {
        $path = trim($path, '/');
        $scope = $path === 'api/v1' || str_starts_with($path, 'api/v1/') ? OAuthScope::Api : OAuthScope::Mcp;

        return [
            ...parent::protectedResourceMetadata($path),
            'scopes_supported' => [$scope->value],
        ];
    }
}
