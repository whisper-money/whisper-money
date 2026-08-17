<?php

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingSyncLogStatus;
use App\Exceptions\Banking\TransientBankingProviderException;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\BankingSyncLog;
use App\Models\User;
use App\Services\Banking\BalanceSyncService;
use App\Services\Banking\TransactionSyncService;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\RequestException;

/**
 * A connection whose accounts the bank serves unevenly. Production had a CaixaBank
 * one in this shape for five weeks: account 1 importing transactions while accounts
 * 2 and 3 sat frozen at the date of the last complete run.
 */
function enableBankingConnectionWithAccounts(int $count): BankingConnection
{
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'status' => BankingConnectionStatus::Active,
        'last_synced_at' => now()->subDay(),
        'consecutive_sync_failures' => 0,
    ]);

    for ($i = 1; $i <= $count; $i++) {
        Account::factory()->connected()->create([
            'user_id' => $user->id,
            'banking_connection_id' => $connection->id,
            'external_account_id' => "ext-{$i}",
        ]);
    }

    return $connection;
}

function aspspError(): TransientBankingProviderException
{
    return new TransientBankingProviderException(
        'EnableBanking bank connector failed while fetching account transactions.',
        provider: 'enablebanking',
        statusCode: 400,
        providerCode: 'ASPSP_ERROR',
    );
}

function finalAttemptJobFor(BankingConnection $connection): SyncBankingConnectionJob
{
    $job = new SyncBankingConnectionJob($connection);
    $job->job = Mockery::mock(Job::class);
    $job->job->shouldReceive('attempts')->andReturn(3);
    $job->job->shouldReceive('isReleased')->andReturn(false);
    $job->job->shouldReceive('isDeletedOrReleased')->andReturn(false);
    $job->job->shouldReceive('hasFailed')->andReturn(false);

    return $job;
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

test('a balance rate limit stops the loop instead of spending what is left', function () {
    $connection = enableBankingConnectionWithAccounts(3);
    // Production's shape: these connections have never recorded a completed run,
    // which is the whole symptom.
    $connection->update(['last_synced_at' => null]);

    $attempted = 0;
    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andReturnUsing(function () use (&$attempted) {
        $attempted++;

        return 5;
    });

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    // The balance call is the one actually spending the allowance, so pin it too.
    $balanceSync->shouldReceive('sync')->once()->andThrow(new RequestException(
        new Illuminate\Http\Client\Response(new Response(429, [], json_encode([
            'code' => 429,
            'message' => 'Maximum daily access exceeded',
        ])))
    ));

    runSync(finalAttemptJobFor($connection), $transactionSync, $balanceSync);

    // The allowance is spent, so the accounts behind this one would only burn what
    // is not there. The run still counts for the account that did import.
    expect($attempted)->toBe(1);

    $connection->refresh();
    expect($connection->last_synced_at)->not->toBeNull();
    expect($connection->rate_limited_until->toIso8601String())
        ->toBe(now()->utc()->addDay()->startOfDay()->toIso8601String());

    // The rate-limited account's rows were imported, so the log has to say so -
    // this is the table we read to size problems like this one.
    $log = BankingSyncLog::where('banking_connection_id', $connection->id)
        ->where('status', BankingSyncLogStatus::Success)->sole();
    expect($log->metadata['transactions_synced'])->toBe(5);
});

test('a rate limit outranks a transaction failure deferred from an earlier account', function () {
    $connection = enableBankingConnectionWithAccounts(3);
    $connection->update(['last_synced_at' => null]);
    $refusedAccountId = $connection->accounts[0]->id;

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andReturnUsing(function ($account) use ($refusedAccountId) {
        if ($account->id === $refusedAccountId) {
            throw aspspError();
        }

        return 5;
    });

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->andThrow(new RequestException(
        new Illuminate\Http\Client\Response(new Response(429, [], json_encode([
            'code' => 429,
            'message' => 'Maximum daily access exceeded',
        ])))
    ));

    runSync(finalAttemptJobFor($connection), $transactionSync, $balanceSync);

    // Throwing the deferred transaction failure here would drop the provider's
    // window, and the scheduler would come straight back for what is left of the
    // allowance. The transaction failure retries next cycle either way.
    $connection->refresh();
    expect($connection->rate_limited_until)->not->toBeNull();
});
