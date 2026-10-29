<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | These domains are the domains that OAuth clients are permitted to use
    | for redirect URIs. Each domain should be specified with its scheme
    | and host. Domains not in this list will raise validation errors.
    |
    | An "*" may be used to allow all domains.
    |
    */

    'redirect_domains' => [
        // Anthropic's hosted callback for Claude Desktop / Claude web connectors.
        'https://claude.ai',
        // OpenAI's hosted callback for ChatGPT connectors.
        'https://chatgpt.com',
        // Loopback callbacks for native clients such as Claude Code (RFC 8252):
        // listing localhost lets laravel/mcp accept any port on localhost,
        // 127.0.0.1 or [::1]. The code can only ever reach the user's own
        // machine, and PKCE is required.
        'http://localhost',
    ],

    /*
    |--------------------------------------------------------------------------
    | Verified Apps
    |--------------------------------------------------------------------------
    |
    | OAuth apps shown as verified on the consent screen and in the connected
    | apps lists, each with the exact hosts its callbacks live on. A client is
    | verified as an app only when every redirect URI it registered is https
    | on one of these hosts (no subdomains, no other port, no credentials), so
    | the authorization code can only ever reach that app. The client's own
    | name is never trusted: anyone can register a client called "Claude".
    |
    */

    'verified_apps' => [
        'Claude' => ['claude.ai'],
        'ChatGPT' => ['chatgpt.com'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Native desktop OAuth clients like Cursor and VS Code use private-use URI
    | schemes (RFC 8252) for redirect callbacks instead of standard schemes
    | like HTTPS. Here, you may list which custom schemes you will allow.
    |
    */

    'custom_schemes' => [
        // 'claude',
        // 'cursor',
        // 'vscode',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | Here you may configure the OAuth authorization server issuer identifier
    | per RFC 8414. This value appears in your protected resource and auth
    | server metadata endpoints. When null, this defaults to `url('/')`.
    |
    | Point this at a dedicated host (e.g. https://oauth.whisper.money) so the
    | authorize/token/register endpoints live outside the PWA's manifest scope.
    | The installed PWA is a verified App Link handler for the app origin, so an
    | on-origin `/oauth/authorize` link (e.g. from ChatGPT on Android) gets
    | captured into the app, where the redirect back to the client can't
    | complete. A separate host keeps the OAuth flow in the browser instead.
    |
    */

    'authorization_server' => env('MCP_AUTHORIZATION_SERVER'),

];
