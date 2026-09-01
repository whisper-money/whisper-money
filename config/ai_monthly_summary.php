<?php

return [

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
    | a growing pause. What never happens is the report waiting: once the
    | attempts are spent, it goes out without the section.
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
