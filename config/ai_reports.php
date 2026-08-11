<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Provider & model
    |--------------------------------------------------------------------------
    |
    | Provider and model for the AI summary that opens the scheduled stats
    | reports (`stats:subscription-funnel`, `stats:ai-cohort-report`). The
    | provider defaults to Gemini but accepts any
    | laravel/ai provider (a valid Laravel\Ai\Enums\Lab case); a Flash-tier
    | model is plenty for summarising a handful of pre-computed figures. See the
    | README "AI Provider" section for the shared AI_PROVIDER switch.
    |
    */

    'provider' => env('AI_REPORTS_PROVIDER', env('AI_PROVIDER', 'gemini')),

    'model' => env('AI_REPORTS_MODEL', 'gemini-flash-latest'),

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | Seconds to wait for the summary before giving up and posting the report
    | without it. The summary is a nice-to-have, so a slow provider must never
    | hold up the report itself.
    |
    */

    'timeout' => (int) env('AI_REPORTS_TIMEOUT', 30),

];
