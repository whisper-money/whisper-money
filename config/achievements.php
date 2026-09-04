<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rollout
    |--------------------------------------------------------------------------
    |
    | Whether the feature resolves on for a reader who has never been resolved
    | before. Off, so the medals stay invisible until someone deliberately turns
    | them on: the switch lives here rather than in the code so the rollout is a
    | variable in Coolify, not a deploy.
    |
    | Pennant stores the resolved value per reader, so turning this on only
    | reaches readers with no stored row. See App\Features\Achievements for the
    | command that has to run alongside it.
    |
    */

    'enabled' => (bool) env('ACHIEVEMENTS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Where streaks start counting
    |--------------------------------------------------------------------------
    |
    | Milestones unlock with the month they really happened, reconstructed from
    | the history. Streaks do not: a streak is a habit kept while the medals
    | were there to keep it for, so the "months in a row" medals only count
    | months from this one on. YYYY-MM.
    |
    */

    'streaks_from' => env('ACHIEVEMENTS_STREAKS_FROM', '2026-09'),

    /*
    |--------------------------------------------------------------------------
    | Rarity
    |--------------------------------------------------------------------------
    |
    | Every medal has a tier assigned by hand (common, uncommon, rare, epic) that
    | is shown everywhere. The real share of members holding it is shown too,
    | but only once at least this many members have been evaluated: below that
    | a percentage is noise dressed up as a fact.
    |
    */

    'rarity_floor' => (int) env('ACHIEVEMENTS_RARITY_FLOOR', 100),

    /*
    |--------------------------------------------------------------------------
    | Money ladders, per currency
    |--------------------------------------------------------------------------
    |
    | In major units. Scaled to what a salary buys locally rather than to the
    | exchange rate: at the rate, an "epic" month in Mexico would be one nobody
    | ever has, and a medal nobody can reach is dead weight on the page. The
    | seven currencies here cover 94% of members; everyone else is measured in
    | the fallback currency, converted at the rate of the month in question.
    |
    | Three ladders, shared by the medals that read them: `monthly` for saving
    | and investing in a month, `yearly` for saving in a calendar year, and
    | `net_worth` for the total across accounts.
    |
    */

    'fallback_currency' => 'USD',

    'ladders' => [
        'USD' => [
            'monthly' => [100, 1000, 2500, 5000],
            'yearly' => [1200, 6000, 12000, 24000],
            'net_worth' => [10000, 25000, 50000, 100000, 250000, 500000, 1000000],
        ],
        'EUR' => [
            'monthly' => [100, 1000, 2500, 5000],
            'yearly' => [1200, 6000, 12000, 24000],
            'net_worth' => [10000, 25000, 50000, 100000, 250000, 500000, 1000000],
        ],
        'MXN' => [
            'monthly' => [1000, 10000, 25000, 50000],
            'yearly' => [12000, 60000, 120000, 240000],
            'net_worth' => [100000, 250000, 500000, 1000000, 2500000, 5000000, 10000000],
        ],
        'COP' => [
            'monthly' => [200000, 2000000, 5000000, 10000000],
            'yearly' => [2400000, 12000000, 24000000, 48000000],
            'net_worth' => [20000000, 50000000, 100000000, 200000000, 500000000, 1000000000, 2000000000],
        ],
        'ARS' => [
            'monthly' => [75000, 750000, 2000000, 4000000],
            'yearly' => [1000000, 5000000, 10000000, 20000000],
            'net_worth' => [10000000, 25000000, 50000000, 100000000, 250000000, 500000000, 1000000000],
        ],
        'PEN' => [
            'monthly' => [200, 2000, 5000, 10000],
            'yearly' => [2400, 12000, 24000, 48000],
            'net_worth' => [20000, 50000, 100000, 200000, 500000, 1000000, 2000000],
        ],
        'CLP' => [
            'monthly' => [50000, 500000, 1200000, 2500000],
            'yearly' => [600000, 3000000, 6000000, 12000000],
            'net_worth' => [5000000, 10000000, 25000000, 50000000, 100000000, 250000000, 500000000],
        ],
    ],

];
