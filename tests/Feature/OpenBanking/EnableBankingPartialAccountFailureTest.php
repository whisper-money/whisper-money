<?php

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingSyncLogStatus;
use App\Exceptions\Banking\TransientBankingProviderException;
use App\Models\Account;
use App\Models\BankingSyncLog;
use App\Services\Banking\BalanceSyncService;
use App\Services\Banking\TransactionSyncService;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\RequestException;

function aspspError(): TransientBankingProviderException
{
    return new TransientBankingProviderException(
        'EnableBanking bank connector failed while fetching account transactions.',
        provider: 'enablebanking',
        statusCode: 400,
        providerCode: 'ASPSP_ERROR',
    );
}

test('an account the bank cannot serve no longer starves the accounts behind it', function () {
    $connection = enableBankingConnectionWithAccounts(3);
    $refusedAccountId = $connection->accounts[1]->id;

    $attempted = [];
    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andReturnUsing(
        function ($account) use ($refusedAccountId, &$attempted) {
            $attempted[] = $account->id;

            if ($account->id === $refusedAccountId) {
                throw aspspError();
            }

            return 5;
        }
    );

    $balancedAccounts = [];
    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->andReturnUsing(function ($account) use (&$balancedAccounts) {
        $balancedAccounts[] = $account->id;
    });
    $balanceSync->shouldReceive('calculateHistoricalBalances')->andReturnNull();

    try {
        runSync(finalAttemptJobFor($connection), $transactionSync, $balanceSync);
    } catch (TransientBankingProviderException) {
        // Expected: the run still fails, see the next test.
    }

    // All three accounts got their turn, including the balance of the one whose
    // transactions the bank refused - balances come from a different endpoint.
    expect($attempted)->toHaveCount(3);
    expect($balancedAccounts)->toHaveCount(3);
});

test('a partially failing run is still recorded as failed', function () {
    $connection = enableBankingConnectionWithAccounts(2);
    $refusedAccountId = $connection->accounts[1]->id;

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andReturnUsing(function ($account) use ($refusedAccountId) {
        if ($account->id === $refusedAccountId) {
            throw aspspError();
        }

        return 5;
    });

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->andReturnNull();
    $balanceSync->shouldReceive('calculateHistoricalBalances')->andReturnNull();

    try {
        runSync(finalAttemptJobFor($connection), $transactionSync, $balanceSync);
    } catch (TransientBankingProviderException) {
        // Expected.
    }

    // Deliberately unchanged from before: an Active badge and a fresh timestamp
    // over an account that is not updating would be a quieter dead end than the
    // error state, and nothing in the UI distinguishes a stale account yet.
    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Error);
    expect($connection->last_synced_at->toDateString())->toBe(now()->subDay()->toDateString());
});

test('a provider that never answered stops the run instead of retrying every account', function () {
    $connection = enableBankingConnectionWithAccounts(3);

    $attempted = 0;
    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andReturnUsing(function () use (&$attempted) {
        $attempted++;

        // No statusCode: the ConnectionException path, i.e. nothing came back.
        throw new TransientBankingProviderException(
            'EnableBanking did not respond while fetching account transactions.',
            provider: 'enablebanking',
        );
    });

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->andReturnNull();
    $balanceSync->shouldReceive('calculateHistoricalBalances')->andReturnNull();

    try {
        runSync(finalAttemptJobFor($connection), $transactionSync, $balanceSync);
    } catch (TransientBankingProviderException) {
        // Expected.
    }

    // Carrying on would spend the client's timeout per account against the job's
    // 120s; a 26-account connection already takes a minute when everything works.
    expect($attempted)->toBe(1);
});

test('a rate limit still reaches the job instead of being swallowed per account', function () {
    $connection = enableBankingConnectionWithAccounts(3);

    $attempted = 0;
    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andReturnUsing(function () use (&$attempted) {
        $attempted++;

        throw new RequestException(new Illuminate\Http\Client\Response(
            new Response(429, [], json_encode(['code' => 429, 'message' => 'Too many requests']))
        ));
    });

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->andReturnNull();
    $balanceSync->shouldReceive('calculateHistoricalBalances')->andReturnNull();

    try {
        runSync(finalAttemptJobFor($connection), $transactionSync, $balanceSync);
    } catch (RequestException) {
        // Expected.
    }

    // A 429 is a raw RequestException, so the new catch must not see it: swallowing
    // it would keep burning a per-consent daily quota account after account.
    expect($attempted)->toBe(1);
    $connection->refresh();
    expect($connection->rate_limited_until)->not->toBeNull();
});

test('a balance rate limit does not make a failed run blame the balances', function () {
    $connection = enableBankingConnectionWithAccounts(2);
    $refusedAccountId = $connection->accounts[1]->id;

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andReturnUsing(function ($account) use ($refusedAccountId) {
        if ($account->id === $refusedAccountId) {
            throw aspspError();
        }

        return 5;
    });

    // Account 1's balances are rate limited, then account 2's transactions end the
    // run: two failures, and only one of them killed it.
    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->once()->andThrow(
        new RequestException(new Illuminate\Http\Client\Response(
            new Response(429, [], json_encode(['code' => 429, 'message' => 'Maximum daily access exceeded']))
        ))
    );

    try {
        runSync(finalAttemptJobFor($connection), $transactionSync, $balanceSync);
    } catch (TransientBankingProviderException) {
        // Expected: the transactions failure is what the job is handed.
    }

    $connection->refresh();
    $log = BankingSyncLog::query()
        ->where('banking_connection_id', $connection->id)
        ->where('status', BankingSyncLogStatus::Failed)
        ->firstOrFail();

    // Handing the job the 429 instead would buy a backoff at the price of the log
    // blaming the balances endpoint, and of the connection sitting Active with a
    // persistent per-account failure nobody is told about.
    expect($log->error_class)->toBe(TransientBankingProviderException::class)
        ->and($connection->status)->toBe(BankingConnectionStatus::Error)
        // The known cost, recorded on the aborted-run warning instead: this run
        // takes no backoff with it, and the next attempt's own 429 applies one.
        ->and($connection->rate_limited_until)->toBeNull();
});
