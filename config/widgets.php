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

];
