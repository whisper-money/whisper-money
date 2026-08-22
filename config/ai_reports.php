<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Provider & model
    |--------------------------------------------------------------------------
    |
    | Provider and model for the AI summary that opens the scheduled stats
    | reports (`stats:subscription-funnel`, `stats:ai-cohort-report`). The
    | provider defaults to Gemini but accepts any laravel/ai provider (a valid
    | Laravel\Ai\Enums\Lab case or a named provider like "orcarouter"); a
    | Flash-tier model is plenty for summarising a handful of pre-computed
    | figures. See the README "AI Provider" section for the shared AI_PROVIDER
    | switch and the OrcaRouter gateway.
    |
    */

    'provider' => env('AI_REPORTS_PROVIDER', env('AI_PROVIDER', 'gemini')),

    'model' => env('AI_REPORTS_MODEL', env('AI_REPORTS_PROVIDER', env('AI_PROVIDER', 'gemini')) === 'orcarouter'
        ? env('ORCAROUTER_MODEL', 'orcarouter/auto')
        : 'gemini-flash-latest'),

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
