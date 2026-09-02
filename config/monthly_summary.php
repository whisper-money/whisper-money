<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rollout
    |--------------------------------------------------------------------------
    |
    | Whether the feature resolves on for a reader who has never been resolved
    | before. Off, so the report stays invisible until someone deliberately
    | turns it on: the switch lives here rather than in the code so the rollout
    | is a variable in Coolify, not a deploy.
    |
    | Pennant stores the resolved value per reader, so turning this on only
    | reaches readers with no stored row. See App\Features\MonthlySummaries for
    | the command that has to run alongside it.
    |
    */

    'enabled' => (bool) env('MONTHLY_SUMMARIES_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Send window
    |--------------------------------------------------------------------------
    |
    | The report goes out on the 3rd, not the 1st: the 1st-of-month jobs that
    | settle loan and real-estate balances have run by then, and a bank that
    | synced overnight is in. Anyone whose month is not ready yet is retried
    | daily until the 10th, and on the 10th the report goes out with whatever is
    | there, saying so.
    |
    | The hour is local to each reader: over a thousand onboarded users are in
    | American timezones, where a fixed Madrid morning is the middle of the
    | night. The command runs hourly and picks whoever it is 9am for.
    |
    */

    'first_day' => (int) env('MONTHLY_SUMMARY_FIRST_DAY', 3),

    'last_day' => (int) env('MONTHLY_SUMMARY_LAST_DAY', 10),

    'send_hour' => (int) env('MONTHLY_SUMMARY_SEND_HOUR', 9),

    /*
    |--------------------------------------------------------------------------
    | Fallback timezone
    |--------------------------------------------------------------------------
    |
    | Readers with no timezone recorded (a few dozen) are treated as being here,
    | matching every other scheduled email in the app.
    |
    */

    'fallback_timezone' => env('MONTHLY_SUMMARY_FALLBACK_TIMEZONE', 'Europe/Madrid'),

    /*
    |--------------------------------------------------------------------------
    | Card rendering
    |--------------------------------------------------------------------------
    |
    | The binary that runs `scripts/render-card.mjs`. Bun is what the production
    | image builds assets with, so it is what is guaranteed to be on PATH there.
    |
    */

    'node_binary' => env('MONTHLY_SUMMARY_NODE_BINARY', 'bun'),

];
