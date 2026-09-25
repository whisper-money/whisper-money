<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Transaction page budget
    |--------------------------------------------------------------------------
    |
    | The most transaction pages one sync run will fetch for a single account,
    | keyed by the bank's `aspsp_name`, with `default` covering every bank not
    | listed and `null` meaning no budget at all.
    |
    | Banks meter a consent at a few accesses a day, and the transactions
    | endpoint is paginated, so a bank that pages finely can spend the whole
    | allowance before the sync ever reaches its balances call. Trade Republic
    | did exactly that: 5,740 transaction requests in the week to 2026-08-21 and
    | not one balance request, ~19 pages per run against an allowance that runs
    | out around there, so every run died on a 429 and their users' net worth
    | read zero.
    |
    | Stopping early costs no history. The provider paginates newest-first, so a
    | run cut short holds the recent end of the window, and the date it stopped
    | at is kept in `accounts.transactions_paginate_before` for the next run to
    | resume from.
    |
    */

    'transaction_page_budget' => [
        'default' => null,
        'Trade Republic' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Beta banks
    |--------------------------------------------------------------------------
    |
    | The banks we badge as beta, in the picker and on the user's connections,
    | keyed by provider. An EnableBanking bank maps its `aspsp_name` to the
    | countries it is beta in, or `*` for all of them; a native provider set to
    | `*` is beta as a whole.
    |
    | EnableBanking's own `beta` flag covers far more connectors than the ones
    | that actually give our users trouble, so badging from it warned people off
    | banks that work fine. This is our curated list of the ones that fail more
    | often, and changing it is a code change on purpose.
    |
    */

    'beta_banks' => [
        'enablebanking' => [
            'Trade Republic' => '*',
            'MyInvestor Banco' => ['ES'],
            'American Express' => ['FR'],
            'Banco Cetelem' => ['ES'],
            'Banco Mediolanum' => ['ES'],
            'DKB' => ['DE'],
        ],
        'kraken' => '*',
    ],

];
