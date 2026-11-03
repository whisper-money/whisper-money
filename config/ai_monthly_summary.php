<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Kill switch for the analysis. Off, no model is asked about anyone: the
    | send goes out on its first pass without the section, and the email drops
    | the block rather than pitching Pro or apologising for an analysis that
    | was never attempted. Analyses already written are still pointed at and
    | stay on the report screen. With `config:cache`, flipping it takes a
    | re-cache (a redeploy) to apply.
    |
    */

    'enabled' => (bool) env('AI_MONTHLY_SUMMARY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Provider & model
    |--------------------------------------------------------------------------
    |
    | Provider and model for the "why this happened" analysis that opens the
    | monthly summary email for Pro users who have granted AI consent. A
    | Flash-tier model is plenty: it reads a payload of figures that are already
    | computed and writes three sentences about them. Mirrors `ai_reports`, and
    | follows the shared AI_PROVIDER switch when nothing more specific is set.
    |
    */

    'provider' => env('AI_MONTHLY_SUMMARY_PROVIDER', env('AI_PROVIDER', 'gemini')),

    'model' => env('AI_MONTHLY_SUMMARY_MODEL', 'gemini-flash-latest'),

    /*
    |--------------------------------------------------------------------------
    | Timeout & retries
    |--------------------------------------------------------------------------
    |
    | Provider failures are almost always transient, and a paying user losing
    | their analysis to a 200 ms hiccup is a bad trade — so a few attempts with
    | a growing pause. These are the attempts inside one send; a send that spends
    | them all against an unreachable model holds the report and comes back
    | later, and only gives up on the section once its own retries are spent.
    |
    */

    'timeout' => (int) env('AI_MONTHLY_SUMMARY_TIMEOUT', 30),

    'attempts' => (int) env('AI_MONTHLY_SUMMARY_ATTEMPTS', 3),

    /*
    |--------------------------------------------------------------------------
    | Length
    |--------------------------------------------------------------------------
    |
    | Hard bound on the analysis, ellipsis included. The block sits above the
    | figures, so a runaway answer would push the whole report below the fold.
    |
    */

    'max_length' => (int) env('AI_MONTHLY_SUMMARY_MAX_LENGTH', 900),

];
