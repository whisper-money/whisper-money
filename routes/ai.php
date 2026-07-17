<?php

use App\Mcp\Servers\WhisperMoneyServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Routes
|--------------------------------------------------------------------------
|
| Remote (streamable HTTP) MCP server. Authenticated with Sanctum personal
| access tokens carrying an `mcp:read` ability, throttled per user. The
| Pro-plan gate is enforced inside each tool so a lapsed subscription stops
| working without the user having to revoke their token.
|
*/

Mcp::web('/mcp', WhisperMoneyServer::class)
    ->middleware(['auth:sanctum', 'abilities:mcp:read', 'throttle:60,1']);
