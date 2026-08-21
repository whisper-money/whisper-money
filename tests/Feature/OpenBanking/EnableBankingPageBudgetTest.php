<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\TransactionSource;
use App\Jobs\SendDailyBankTransactionsSyncedEmailJob;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Account;
use App\Services\Banking\BalanceSyncService;
use App\Services\Banking\TransactionDescriptionFormatter;
use App\Services\Banking\TransactionSyncService;
use Carbon\Carbon;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Queue;

/**
 * A provider serving one transaction per page, newest first, a day older each
 * page - the pagination Enable Banking really does, and the shape that let
 * Trade Republic spend ~19 requests a run on an allowance that runs out around
 * there. `$newestDate` dates page one; `$lastPage` is the page the continuation
 * key stops at, so a test can pick between a run the budget cuts short and one
 * that ends by itself.
 */
function pagingProvider(array &$requests, string $newestDate, int $lastPage = 1_000): BankingProviderInterface
{
    $provider = Mockery::mock(BankingProviderInterface::class);
    $provider->shouldReceive('getTransactions')->andReturnUsing(
        function (string $accountId, string $dateFrom, string $dateTo) use (&$requests, $newestDate, $lastPage) {
            $requests[] = ['date_from' => $dateFrom, 'date_to' => $dateTo];
            $page = count($requests);

            return [
                'transactions' => [[
                    'transaction_id' => "txn-{$page}",
                    'transaction_amount' => ['amount' => '10.00', 'currency' => 'EUR'],
                    'credit_debit_indicator' => 'DBIT',
                    'booking_date' => Carbon::parse($newestDate)->subDays($page - 1)->toDateString(),
                    'remittance_information' => ["Page {$page}"],
                ]],
                'continuation_key' => $page < $lastPage ? "key-{$page}" : null,
            ];
        }
    );

    return $provider;
}

function budgetedAccount(): Account
{
    $connection = enableBankingConnectionWithAccounts(1);

    return $connection->accounts[0];
}

function rateLimitedTransactions(): RequestException
{
    return new RequestException(new ClientResponse(
        new PsrResponse(429, [], json_encode(['code' => 429, 'message' => 'Too many requests']))
    ));
}

test('a run the page budget cuts short records the date the next run resumes from', function () {
    $account = budgetedAccount();
    $requests = [];

    // Pages dated 2026-06-08, -07, -06: newest first, as the provider serves them.
    $service = new TransactionSyncService(
        pagingProvider($requests, '2026-06-08'),
        new TransactionDescriptionFormatter,
    );

    $created = $service->sync($account, '2025-08-21', '2026-08-21', pageBudget: 3);

    expect($requests)->toHaveCount(3)
        ->and($created)->toBe(3);

    $account->refresh();
    expect($account->transactions_paginate_before->toDateString())->toBe('2026-06-06');
});

test('a run that paginates to the end owes nothing and leaves no marker', function () {
    $account = budgetedAccount();
    $account->update(['transactions_paginate_before' => '2026-06-03']);
    $requests = [];

    $service = new TransactionSyncService(
        pagingProvider($requests, '2026-06-01', lastPage: 2),
        new TransactionDescriptionFormatter,
    );

    $service->sync($account, '2025-08-21', '2026-06-03', pageBudget: 5);

    expect($requests)->toHaveCount(2);

    $account->refresh();
    expect($account->transactions_paginate_before)->toBeNull();
});

test('a cut-short run that reached nothing older steps over the day instead of looping on it', function () {
    $account = budgetedAccount();
    $account->update(['transactions_paginate_before' => '2026-06-03']);
    $requests = [];

    // The one page the budget allows is dated on the window's own end date, so
    // resuming there would re-request the same page every cycle and never reach
    // the history behind it - which is how a day paginated more finely than the
    // budget, or a run of pages carrying nothing older, would silently swallow
    // the rest of the account's history.
    $service = new TransactionSyncService(
        pagingProvider($requests, '2026-06-03'),
        new TransactionDescriptionFormatter,
    );

    $service->sync($account, '2025-08-21', '2026-06-03', pageBudget: 1);

    $account->refresh();
    expect($account->transactions_paginate_before->toDateString())->toBe('2026-06-02');
});

test('a cut-short run over a window holding nothing at all owes no more history', function () {
    $account = budgetedAccount();
    $account->update(['transactions_paginate_before' => '2026-06-03']);

    // Pages, and a continuation key, but no transactions in any of them: there
    // is nothing behind this window to come back for.
    $provider = Mockery::mock(BankingProviderInterface::class);
    $provider->shouldReceive('getTransactions')->andReturn([
        'transactions' => [],
        'continuation_key' => 'more',
    ]);

    $service = new TransactionSyncService($provider, new TransactionDescriptionFormatter);
    $service->sync($account, '2025-08-21', '2026-06-03', pageBudget: 2);

    $account->refresh();
    expect($account->transactions_paginate_before)->toBeNull();
});

test('a bank with no budget configured paginates to the end as before', function () {
    $account = budgetedAccount();
    $requests = [];

    $service = new TransactionSyncService(
        pagingProvider($requests, '2026-06-01', lastPage: 4),
        new TransactionDescriptionFormatter,
    );

    $service->sync($account, '2025-08-21', '2026-08-21');

    expect($requests)->toHaveCount(4);

    $account->refresh();
    expect($account->transactions_paginate_before)->toBeNull();
});

test('the budget is read per bank from config, and an unlisted bank stays unbudgeted', function () {
    config(['banking.transaction_page_budget' => ['default' => null, 'Metered Bank' => 2]]);

    $connection = enableBankingConnectionWithAccounts(1);
    $connection->update(['aspsp_name' => 'Metered Bank']);

    $budgets = [];
    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andReturnUsing(
        function ($account, $dateFrom, $dateTo, $strategy, $saveDailyBalances, $pageBudget) use (&$budgets) {
            $budgets[] = $pageBudget;

            return 0;
        }
    );

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->andReturnNull();
    $balanceSync->shouldReceive('calculateHistoricalBalances')->andReturnNull();

    runSync(new SyncBankingConnectionJob($connection->refresh()), $transactionSync, $balanceSync);

    expect($budgets)->toBe([2]);

    config(['banking.transaction_page_budget' => ['default' => null]]);
    $budgets = [];

    runSync(new SyncBankingConnectionJob($connection->refresh()), $transactionSync, $balanceSync);

    expect($budgets)->toBe([PHP_INT_MAX]);
});

test('the balance request is made before the pagination that used to starve it', function () {
    $connection = enableBankingConnectionWithAccounts(2);

    $calls = [];

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andReturnUsing(function () use (&$calls) {
        $calls[] = 'transactions';

        return 0;
    });

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->andReturnUsing(function () use (&$calls) {
        $calls[] = 'balances';
    });
    $balanceSync->shouldReceive('calculateHistoricalBalances')->andReturnNull();

    runSync(new SyncBankingConnectionJob($connection), $transactionSync, $balanceSync);

    expect($calls)->toBe(['balances', 'transactions', 'balances', 'transactions']);
});

test('a rate limit on the transactions call no longer costs the account its balance', function () {
    $connection = enableBankingConnectionWithAccounts(1);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andThrow(rateLimitedTransactions());

    $balanced = 0;
    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->andReturnUsing(function () use (&$balanced) {
        $balanced++;
    });
    $balanceSync->shouldReceive('calculateHistoricalBalances')->andReturnNull();

    try {
        runSync(finalAttemptJobFor($connection), $transactionSync, $balanceSync);
    } catch (RequestException) {
        // Expected: the run still fails, which is what applies the backoff.
    }

    // The whole point. In production this request was never made once in a week
    // of Trade Republic syncs, because the 429 landed before the sync got here.
    expect($balanced)->toBe(1);

    // And the backoff the 429 exists to trigger is untouched: no reclassifying
    // the exception, no reporting it as a success.
    $connection->refresh();
    expect($connection->rate_limited_until)->not->toBeNull();
});

test('the next run resumes the history at the marker instead of the watermark', function () {
    $connection = enableBankingConnectionWithAccounts(1);
    $connection->accounts[0]->update(['transactions_paginate_before' => '2026-05-01']);

    // A transaction dated today: the watermark a routine window would be built
    // on, and the reason a naive page cap would never reach the old history.
    $connection->accounts[0]->transactions()->create([
        'user_id' => $connection->user_id,
        'description' => 'Recent',
        'transaction_date' => now()->toDateString(),
        'amount' => -100,
        'currency_code' => 'EUR',
        'source' => TransactionSource::EnableBanking,
    ]);

    $window = null;
    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andReturnUsing(
        function ($account, $dateFrom, $dateTo) use (&$window) {
            $window = [$dateFrom, $dateTo];

            return 0;
        }
    );

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->andReturnNull();
    $balanceSync->shouldReceive('calculateHistoricalBalances')->andReturnNull();

    runSync(new SyncBankingConnectionJob($connection), $transactionSync, $balanceSync);

    expect($window)->toBe([now()->subYear()->toDateString(), '2026-05-01']);
});

test('a budgeted bank walks its history across runs instead of re-reading the same pages', function () {
    Carbon::setTestNow('2026-08-21 09:00:00');
    config(['banking.transaction_page_budget' => ['default' => null, 'Metered Bank' => 2]]);

    $connection = enableBankingConnectionWithAccounts(1);
    $connection->update(['aspsp_name' => 'Metered Bank']);

    $requests = [];
    $provider = pagingProvider($requests, '2026-06-10');
    $provider->shouldReceive('getBalances')->twice()->andReturn(['balances' => []]);
    app()->instance(BankingProviderInterface::class, $provider);

    // Two runs of the real syncer over the real transaction service, so the
    // marker written by one is the window read by the next.
    runSync(new SyncBankingConnectionJob($connection->refresh()));
    runSync(new SyncBankingConnectionJob($connection->refresh()));

    // Both runs reached the balances endpoint, which is the request Trade
    // Republic never once made in a week of syncs (Mockery asserts the count).
    // Run one took pages 1-2 of a window ending today; run two picked the
    // history up at the date it stopped rather than at today's watermark, which
    // is what a page cap without a marker would have done for ever.
    expect($requests)->toHaveCount(4)
        ->and($requests[0]['date_to'])->toBe('2026-08-21')
        ->and($requests[2]['date_to'])->toBe('2026-06-09');

    // And the older history the second run reached is actually in the database.
    expect($connection->accounts[0]->transactions()->pluck('transaction_date')
        ->map(fn ($date) => $date->toDateString())->sort()->values()->all())
        ->toBe(['2026-06-07', '2026-06-08', '2026-06-09', '2026-06-10']);
});

test('a marker the full window has closed over is ignored instead of inverting the window', function () {
    Carbon::setTestNow('2026-08-21 09:00:00');

    $connection = enableBankingConnectionWithAccounts(1);

    // Written when a year back still reached past it; a year back is now later.
    $connection->accounts[0]->update(['transactions_paginate_before' => '2025-08-01']);

    $window = null;
    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andReturnUsing(
        function ($account, $dateFrom, $dateTo) use (&$window) {
            $window = [$dateFrom, $dateTo];

            return 0;
        }
    );

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->andReturnNull();
    $balanceSync->shouldReceive('calculateHistoricalBalances')->andReturnNull();

    runSync(new SyncBankingConnectionJob($connection), $transactionSync, $balanceSync);

    expect($window)->toBe(['2025-08-21', '2026-08-21']);
});

test('a run still owing history moves the email cutoff instead of announcing it as new', function () {
    Queue::fake();
    Carbon::setTestNow('2026-08-21 09:00:00');
    config(['banking.transaction_page_budget' => ['default' => null, 'Metered Bank' => 2]]);

    $connection = enableBankingConnectionWithAccounts(1);
    $connection->update(['aspsp_name' => 'Metered Bank']);

    $requests = [];
    $provider = pagingProvider($requests, '2026-06-10');
    $provider->shouldReceive('getBalances')->andReturn(['balances' => []]);
    app()->instance(BankingProviderInterface::class, $provider);

    runSync(new SyncBankingConnectionJob($connection->refresh()));

    // The rows this run imported are dated June; the daily email keys on when a
    // row was written, so sending it would report a backfill as today's news.
    Queue::assertNotPushed(SendDailyBankTransactionsSyncedEmailJob::class);

    $connection->refresh();
    expect($connection->bank_transactions_email_cutoff_at->toDateTimeString())->toBe('2026-08-21 09:00:00');
});

test('a full resync re-pulls the whole history instead of resuming a leftover marker', function () {
    Carbon::setTestNow('2026-08-21 09:00:00');

    $connection = enableBankingConnectionWithAccounts(1);
    $connection->accounts[0]->update(['transactions_paginate_before' => '2026-05-01']);

    $window = null;
    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andReturnUsing(
        function ($account, $dateFrom, $dateTo) use (&$window) {
            $window = [$dateFrom, $dateTo];

            return 0;
        }
    );

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->andReturnNull();
    $balanceSync->shouldReceive('calculateHistoricalBalances')->andReturnNull();

    runSync(new SyncBankingConnectionJob($connection, fullSync: true), $transactionSync, $balanceSync);

    // --full is an explicit request for everything; a marker left by a run the
    // budget cut short must not quietly narrow it back down.
    expect($window)->toBe(['2025-08-21', '2026-08-21']);
});
