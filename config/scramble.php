<?php

use Dedoc\Scramble\OpenApiVersion;

/*
|--------------------------------------------------------------------------
| OpenAPI spec of the public API
|--------------------------------------------------------------------------
|
| Scramble generates the `/api/v1` spec from the code. Only the JSON is
| public, at `GET /api/v1/openapi.json`; Scramble's own UI and JSON routes
| are never registered (see ApiDocumentationServiceProvider). The reference
| pages under resources/docs/documentation/60-integrations/20-api/ are
| generated from the same spec by `php artisan api:docs`.
|
*/

return [
    'api_path' => 'api/v1',

    'api_domain' => null,

    'export_path' => 'api.json',

    /*
     * `php artisan scramble:cache` (run on deploy) stores the generated spec
     * here, and `GET /api/v1/openapi.json` serves it from the cache.
     */
    'cache' => [
        'key' => 'scramble.openapi',
        'store' => env('SCRAMBLE_CACHE_STORE', 'file'),
    ],

    'openapi_version' => OpenApiVersion::V3_1,

    'info' => [
        'version' => 'v1',

        'description' => 'The Whisper Money REST API: your accounts, transactions and the rest of your data, for Pro users. '
            .'Authenticate with a personal API token or an OAuth access token granted `api:use`. '
            .'Amounts are integers in the currency\'s minor unit. Guides: https://whisper.money/documentation/api',
    ],

    'ui' => [
        'title' => 'Whisper Money API',
    ],

    'dev_tools' => [
        'enabled' => false,
    ],

    'renderer' => 'elements',

    'renderers' => [
        'elements' => [
            'view' => 'scramble::docs',
        ],
    ],

    /*
     * Pinned to APP_URL by ApiDocumentationServiceProvider, so the spec never
     * depends on the request host.
     */
    'servers' => null,

    'enum_cases_description_strategy' => 'description',

    'enum_cases_names_strategy' => false,

    'flatten_deep_query_parameters' => true,

    'middleware' => [],

    'extensions' => [],

    'security_strategy' => null,
];
