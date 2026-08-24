<?php

use App\Mcp\Servers\WhisperMoneyServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Routes
|--------------------------------------------------------------------------
|
| Remote (streamable HTTP) MCP server, exposed on two endpoints:
|
| - `/mcp` is authenticated with Sanctum personal access tokens carrying an
|   `mcp:read` ability (Claude Code, static bearer token, read/read_write via
|   abilities). Left completely unchanged.
| - `/mcp/oauth` is authenticated with OAuth 2.1 (Authorization Code + PKCE)
|   for clients that sign in rather than paste a token — Claude Desktop / web
|   and ChatGPT connectors. `Mcp::oauthRoutes()` registers the RFC 8414 / 9728
|   discovery endpoints, the RFC 7591 DCR endpoint and the `mcp:use` Passport
|   scope; the package's AddWwwAuthenticateHeader middleware then turns an
|   unauthenticated request into the mandatory 401 bootstrap challenge.
|
| OAuth connections carry the single `mcp:use` scope and are trusted with
| read and write: the user approved the connection on the consent screen, so
| WriteTool grants write to anything on the `api` guard. Sanctum personal
| access tokens still need the `mcp:write` ability, so a read-only token can
| analyse data but never change it. The Pro-plan gate is enforced inside each
| tool under either guard, so a lapsed subscription stops working without
| revoking access.
|
| `throttle:mcp` is a named limiter (see AppServiceProvider): 60 requests a
| minute per user, raised for the shared demo/press accounts, which several
| people drive at the same time.
|
*/

Mcp::oauthRoutes();

Mcp::web('/mcp', WhisperMoneyServer::class)
    ->middleware(['auth:sanctum', 'abilities:mcp:read', 'throttle:mcp']);

Mcp::web('/mcp/oauth', WhisperMoneyServer::class)
    ->middleware(['auth:api', 'throttle:mcp']);
