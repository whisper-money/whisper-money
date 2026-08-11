<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    |
    | These options configures if and how Inertia uses Server Side Rendering
    | to pre-render each initial request made to your application's pages
    | so that server rendered HTML is delivered for the user's browser.
    |
    | See: https://inertiajs.com/server-side-rendering
    |
    */

    'ssr' => [
        'enabled' => true,
        'url' => 'http://127.0.0.1:13714',
        // 'bundle' => base_path('bootstrap/ssr/ssr.mjs'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | Where Inertia page components live on the filesystem. Used both to
    | resolve components at runtime and to locate them from `assertInertia`.
    |
    */

    'pages' => [

        'paths' => [
            resource_path('js/pages'),
        ],

        'extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | When using `assertInertia`, the assertion attempts to locate the component
    | as a file relative to the `pages.paths` AND with any of the
    | `pages.extensions` specified above.
    |
    */

    'testing' => [

        'ensure_pages_exist' => true,

    ],

    /*
    |--------------------------------------------------------------------------
    | DevTools
    |--------------------------------------------------------------------------
    |
    | Inertia v3 records every request to disk so the DevTools extension can
    | read it back, and defaults to on in local. Those dumps hold whole page
    | prop payloads — transactions, balances — and redaction only covers
    | credential keys, so we keep it opt-in. Set INERTIA_DEVTOOLS_ENABLED=true
    | in your own .env when you actually need it.
    |
    */

    'devtools' => [

        'enabled' => env('INERTIA_DEVTOOLS_ENABLED', false),

    ],

];
