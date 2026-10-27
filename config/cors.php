<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | Only the public API is open cross-origin, to any origin, so browser apps
    | (an OAuth SPA with PKCE, for one) can call it. That is safe because
    | `/api/v1` authenticates with bearer tokens alone and never reads a
    | cookie, which is also why credentials stay unsupported. The internal
    | session API (`/api/*`) and everything else stay same-origin.
    |
    */

    'paths' => ['api/v1', 'api/v1/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'Retry-After',
        'Idempotent-Replayed',
    ],

    'max_age' => 86400,

    'supports_credentials' => false,

];
