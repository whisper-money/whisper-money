<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Widget Data Gateway
    |--------------------------------------------------------------------------
    |
    | The gateway resolves the API calls a dashboard widget's manifest
    | declares, server side and as the signed-in user. It has its own limit,
    | apart from the public API's 60 requests a minute, so opening a dashboard
    | does not spend the budget of the user's integrations: every internal
    | call counts, except one repeated within the same resolution.
    |
    | `max_rows` caps what a call with `paginate: "all"` reads; past it the
    | result is marked `truncated`.
    |
    */

    'gateway' => [
        'calls_per_minute' => (int) env('WIDGET_GATEWAY_CALLS_PER_MINUTE', 300),
        'max_rows' => (int) env('WIDGET_GATEWAY_MAX_ROWS', 10000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Widget runtime errors
    |--------------------------------------------------------------------------
    |
    | How many runtime errors the dashboard may report for one widget a minute.
    | Only the latest is kept, for the agent that wrote the widget to read.
    |
    */

    'errors' => [
        'reports_per_minute' => (int) env('WIDGET_ERRORS_PER_MINUTE', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Widget compiler
    |--------------------------------------------------------------------------
    |
    | User widgets (agent-written TSX) are validated and compiled with Bun when
    | they are saved, synchronously, so create_widget can hand the errors back
    | in its response. A warm compile takes about 150ms end to end and a cold
    | one (first Bun start in a fresh container) well under a second; the
    | timeout only exists so a stuck process cannot hold a request.
    |
    */

    'compiler' => [
        'bun_binary' => env('WIDGETS_BUN_BINARY', 'bun'),

        'timeout' => (int) env('WIDGETS_COMPILE_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Widget renderer
    |--------------------------------------------------------------------------
    |
    | render_widget draws a widget in headless Chromium (scripts/render-widget.mjs)
    | inside the MCP request, so the agent reads the result in the same turn.
    | A cold browser start and a chart take a second or two; past the timeout
    | the browser answers with what it has, so a widget stuck in a loop costs
    | the agent a timeout error rather than holding the request.
    |
    */

    'renderer' => [
        'timeout' => (int) env('WIDGETS_RENDER_TIMEOUT', 20),
    ],

];
