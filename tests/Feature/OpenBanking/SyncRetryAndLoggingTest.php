<?php

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingSyncLogStatus;
use App\Exceptions\Banking\ExpiredBankingSessionException;
use App\Exceptions\Banking\InaccessibleBankAccountException;
use App\Exceptions\Banking\TransientBankingProviderException;
use App\Exceptions\Banking\WrongTransactionsPeriodException;
use App\Jobs\SyncAllBankingConnectionsJob;
use App\Jobs\SyncBankingConnectionJob;
use App\Mail\BankingConnectionExpiredEmail;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\BankingSyncLog;
use App\Models\User;
use App\Services\Banking\BalanceSyncService;
use App\Services\Banking\TransactionSyncService;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

// --- Retry Behavior Tests ---

test('temporary error on non-final attempt does not set error status', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andThrow(
        new RequestException(
            new Illuminate\Http\Client\Response(new Response(500))
        )
    );

    $balanceSync = Mockery::mock(BalanceSyncService::class);

    // Simulate the first attempt, with one still to come
    $job = new SyncBankingConnectionJob($connection);
    $job->job = Mockery::mock(Job::class);
    $job->job->shouldReceive('attempts')->andReturn(1);
    $job->job->shouldReceive('isReleased')->andReturn(false);
    $job->job->shouldReceive('isDeletedOrReleased')->andReturn(false);
    $job->job->shouldReceive('hasFailed')->andReturn(false);

    $threw = false;

    try {
        runSync($job, $transactionSync, $balanceSync);
    } catch (RequestException) {
        $threw = true;
    }

    expect($threw)->toBeTrue();

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->error_message)->toBeNull();
    expect($connection->consecutive_sync_failures)->toBe(0);
});

test('the second attempt is the last one a failing sync gets', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andThrow(
        new TransientBankingProviderException(
            'EnableBanking bank connector failed while fetching account transactions.',
            provider: 'enablebanking',
            statusCode: 400,
            providerCode: 'ASPSP_ERROR',
            operation: 'transactions',
        )
    );

    $balanceSync = Mockery::mock(BalanceSyncService::class);

    $job = new SyncBankingConnectionJob($connection);
    $job->job = Mockery::mock(Job::class);
    $job->job->shouldReceive('attempts')->andReturn(2);
    $job->job->shouldReceive('isReleased')->andReturn(false);
    $job->job->shouldReceive('isDeletedOrReleased')->andReturn(false);
    $job->job->shouldReceive('hasFailed')->andReturn(false);

    try {
        runSync($job, $transactionSync, $balanceSync);
    } catch (TransientBankingProviderException) {
        // Expected
    }

    // A third attempt would re-sync every account against a metered consent for a
    // 1.1% chance of recovery, so the run gives up here and waits for the next
    // scheduled cycle. The counter stays put: the outage is not the connection's
    // fault, and spending it would drop the connection out of that rotation.
    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Error);
    expect($connection->error_message)->not->toBeNull();
    expect($connection->consecutive_sync_failures)->toBe(0);
});

test('temporary error on final attempt sets error status and increments consecutive failures', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andThrow(
        new RequestException(
            new Illuminate\Http\Client\Response(new Response(500))
        )
    );

    $balanceSync = Mockery::mock(BalanceSyncService::class);

    // Simulate an attempt past the retry budget
    $job = new SyncBankingConnectionJob($connection);
    $job->job = Mockery::mock(Job::class);
    $job->job->shouldReceive('attempts')->andReturn(3);
    $job->job->shouldReceive('isReleased')->andReturn(false);
    $job->job->shouldReceive('isDeletedOrReleased')->andReturn(false);
    $job->job->shouldReceive('hasFailed')->andReturn(false);

    try {
        runSync($job, $transactionSync, $balanceSync);
    } catch (RequestException) {
        // Expected
    }

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Error);
    expect($connection->error_message)->toContain('provider is experiencing issues');
    expect($connection->consecutive_sync_failures)->toBe(1);
});

test('temporary error on non-final attempt is not logged', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andThrow(
        new RequestException(
            new Illuminate\Http\Client\Response(new Response(500))
        )
    );

    $balanceSync = Mockery::mock(BalanceSyncService::class);

    Log::spy();

    // Attempt 1 of 3: the retry will recover, so nothing should be reported yet.
    $job = new SyncBankingConnectionJob($connection);
    $job->job = Mockery::mock(Job::class);
    $job->job->shouldReceive('attempts')->andReturn(1);
    $job->job->shouldReceive('isReleased')->andReturn(false);
    $job->job->shouldReceive('isDeletedOrReleased')->andReturn(false);
    $job->job->shouldReceive('hasFailed')->andReturn(false);

    try {
        runSync($job, $transactionSync, $balanceSync);
    } catch (RequestException) {
        // Expected: rethrown so the queue retries it.
    }

    Log::shouldNotHaveReceived('log');
});

test('temporary error on final attempt is logged', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andThrow(
        new RequestException(
            new Illuminate\Http\Client\Response(new Response(500))
        )
    );

    $balanceSync = Mockery::mock(BalanceSyncService::class);

    Log::spy();

    // Final attempt (3 of 3): the connection gives up, so it must be reported.
    $job = new SyncBankingConnectionJob($connection);
    $job->job = Mockery::mock(Job::class);
    $job->job->shouldReceive('attempts')->andReturn(3);
    $job->job->shouldReceive('isReleased')->andReturn(false);
    $job->job->shouldReceive('isDeletedOrReleased')->andReturn(false);
    $job->job->shouldReceive('hasFailed')->andReturn(false);

    try {
        runSync($job, $transactionSync, $balanceSync);
    } catch (RequestException) {
        // Expected
    }

    Log::shouldHaveReceived('log')->with('error', 'Banking sync failed', Mockery::any())->once();
});

test('transient banking provider error on final attempt uses retry later message', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
        'consecutive_sync_failures' => 2,
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andThrow(
        new TransientBankingProviderException(
            'EnableBanking bank connector failed while fetching account transactions.',
            provider: 'enablebanking',
            statusCode: 400,
            providerCode: 'ASPSP_ERROR',
        )
    );

    $balanceSync = Mockery::mock(BalanceSyncService::class);

    $job = new SyncBankingConnectionJob($connection);
    $job->job = Mockery::mock(Job::class);
    $job->job->shouldReceive('attempts')->andReturn(3);
    $job->job->shouldReceive('isReleased')->andReturn(false);
    $job->job->shouldReceive('isDeletedOrReleased')->andReturn(false);
    $job->job->shouldReceive('hasFailed')->andReturn(false);

    $threw = false;

    try {
        runSync($job, $transactionSync, $balanceSync);
    } catch (TransientBankingProviderException) {
        $threw = true;
    }

    expect($threw)->toBeTrue();

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Error);
    expect($connection->error_message)->toContain('bank provider is temporarily unavailable');

    // A provider outage must not spend the retry budget: at MAX_SCHEDULED_RETRIES
    // the connection drops out of every scheduled sync for good.
    expect($connection->consecutive_sync_failures)->toBe(2)
        ->toBeLessThan(SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES);
});

test('a non-transient error on final attempt still spends a scheduled retry', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
        'consecutive_sync_failures' => 2,
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andThrow(new RuntimeException('something we did not classify'));

    $balanceSync = Mockery::mock(BalanceSyncService::class);

    $job = new SyncBankingConnectionJob($connection);
    $job->job = Mockery::mock(Job::class);
    $job->job->shouldReceive('attempts')->andReturn(3);
    $job->job->shouldReceive('isReleased')->andReturn(false);
    $job->job->shouldReceive('isDeletedOrReleased')->andReturn(false);
    $job->job->shouldReceive('hasFailed')->andReturn(false);

    try {
        runSync($job, $transactionSync, $balanceSync);
    } catch (RuntimeException) {
        // expected
    }

    $connection->refresh();
    expect($connection->consecutive_sync_failures)->toBe(3);
});

test('expired session marks the connection expired and emails the user instead of reporting an error', function () {
    Mail::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
        'valid_until' => now()->addMonths(3),
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andThrow(
        new ExpiredBankingSessionException(
            'EnableBanking session expired while fetching account transactions.',
        )
    );

    $balanceSync = Mockery::mock(BalanceSyncService::class);

    $job = new SyncBankingConnectionJob($connection);

    // An expired session is an expected lifecycle event: it must not throw or be reported.
    runSync($job, $transactionSync, $balanceSync);

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Expired);

    $log = BankingSyncLog::where('banking_connection_id', $connection->id)->first();
    expect($log->status)->toBe(BankingSyncLogStatus::Skipped);
    expect($log->metadata)->toBe(['reason' => 'expired']);

    Mail::assertQueued(BankingConnectionExpiredEmail::class, function (BankingConnectionExpiredEmail $mail) use ($user, $connection) {
        return $mail->user->is($user)
            && $mail->bankingConnection->is($connection);
    });
});

test('an inaccessible account is skipped and the rest of the connection still syncs', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'good-account',
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'dead-account',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andReturnUsing(function ($account) {
        if ($account->external_account_id === 'dead-account') {
            throw new InaccessibleBankAccountException('EnableBanking account is no longer accessible.');
        }

        return 2;
    });

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->once();

    $job = new SyncBankingConnectionJob($connection);

    // One dead account must not break the whole connection sync or be reported.
    runSync($job, $transactionSync, $balanceSync);

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->error_message)->toBeNull();

    $log = BankingSyncLog::where('banking_connection_id', $connection->id)->first();
    expect($log->status)->toBe(BankingSyncLogStatus::Success);
    expect($log->metadata['transactions_synced'])->toBe(2);
});

test('an account whose period the bank refuses is skipped, not crashed, and the rest still syncs', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'good-account',
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'narrow-refused-account',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andReturnUsing(function ($account) {
        if ($account->external_account_id === 'narrow-refused-account') {
            throw new WrongTransactionsPeriodException('EnableBanking refused every window.');
        }

        return 2;
    });

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->once();

    $job = new SyncBankingConnectionJob($connection);

    // The refused account must not break the connection sync, mark it Error, or
    // be reported — same resilience as an inaccessible account.
    runSync($job, $transactionSync, $balanceSync);

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->error_message)->toBeNull();

    $log = BankingSyncLog::where('banking_connection_id', $connection->id)->first();
    expect($log->status)->toBe(BankingSyncLogStatus::Success);
    expect($log->metadata['transactions_synced'])->toBe(2);
});

test('consecutive sync failures accumulate across dispatch cycles', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->error()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
        'consecutive_sync_failures' => 2,
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andThrow(
        new RequestException(
            new Illuminate\Http\Client\Response(new Response(500))
        )
    );

    $balanceSync = Mockery::mock(BalanceSyncService::class);

    // Simulate final attempt of a new dispatch cycle
    $job = new SyncBankingConnectionJob($connection);
    $job->job = Mockery::mock(Job::class);
    $job->job->shouldReceive('attempts')->andReturn(3);
    $job->job->shouldReceive('isReleased')->andReturn(false);
    $job->job->shouldReceive('isDeletedOrReleased')->andReturn(false);
    $job->job->shouldReceive('hasFailed')->andReturn(false);

    try {
        runSync($job, $transactionSync, $balanceSync);
    } catch (RequestException) {
        // Expected
    }

    $connection->refresh();
    expect($connection->consecutive_sync_failures)->toBe(3);
});

test('successful sync resets consecutive failures', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->error()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
        'consecutive_sync_failures' => 2,
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->once()->andReturn(0);

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->once();

    $job = new SyncBankingConnectionJob($connection);
    runSync($job, $transactionSync, $balanceSync);

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->error_message)->toBeNull();
    expect($connection->consecutive_sync_failures)->toBe(0);
});

test('connection in error status can be synced', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->error()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->once()->andReturn(3);

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->once();

    $job = new SyncBankingConnectionJob($connection);
    runSync($job, $transactionSync, $balanceSync);

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->last_synced_at)->not->toBeNull();
    expect($connection->error_message)->toBeNull();
});

test('auth error sets consecutive failures beyond the cap', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->indexaCapital()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'IC-001',
    ]);

    Http::fake([
        'api.indexacapital.com/*' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $balanceSync = Mockery::mock(BalanceSyncService::class);

    $job = new SyncBankingConnectionJob($connection);
    $job->job = Mockery::mock(Job::class);
    $job->job->shouldReceive('attempts')->andReturn(1);
    $job->job->shouldReceive('isReleased')->andReturn(false);
    $job->job->shouldReceive('isDeletedOrReleased')->andReturn(false);
    $job->job->shouldReceive('hasFailed')->andReturn(false);
    $job->job->shouldReceive('fail')->once();

    try {
        runSync($job, $transactionSync, $balanceSync);
    } catch (RequestException) {
        // Expected for auth failures after manually failing the job.
    }

    $connection->refresh();
    expect($connection->consecutive_sync_failures)->toBe(SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES + 1);
});

// --- Sync Log Tests ---

test('successful sync creates a success log entry', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->once()->andReturn(5);

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->once();

    $job = new SyncBankingConnectionJob($connection);
    runSync($job, $transactionSync, $balanceSync);

    $log = BankingSyncLog::where('banking_connection_id', $connection->id)->first();
    expect($log)->not->toBeNull();
    expect($log->status)->toBe(BankingSyncLogStatus::Success);
    expect($log->error_message)->toBeNull();
    expect($log->error_class)->toBeNull();
    expect($log->duration_ms)->toBeGreaterThanOrEqual(0);
    expect($log->metadata['transactions_synced'])->toBe(5);
    expect($log->metadata)->toHaveKey('transactions_per_bank');
});

test('failed sync creates a failed log entry', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andThrow(
        new RequestException(
            new Illuminate\Http\Client\Response(new Response(500))
        )
    );

    $balanceSync = Mockery::mock(BalanceSyncService::class);

    $job = new SyncBankingConnectionJob($connection);
    $job->job = Mockery::mock(Job::class);
    $job->job->shouldReceive('attempts')->andReturn(1);
    $job->job->shouldReceive('isReleased')->andReturn(false);
    $job->job->shouldReceive('isDeletedOrReleased')->andReturn(false);
    $job->job->shouldReceive('hasFailed')->andReturn(false);

    try {
        runSync($job, $transactionSync, $balanceSync);
    } catch (RequestException) {
        // Expected
    }

    $log = BankingSyncLog::where('banking_connection_id', $connection->id)->first();
    expect($log)->not->toBeNull();
    expect($log->status)->toBe(BankingSyncLogStatus::Failed);
    expect($log->attempt)->toBe(1);
    expect($log->error_message)->not->toBeNull();
    expect($log->error_class)->toBe(RequestException::class);
});

test('skipped sync creates a skipped log entry and emails user for newly expired connection', function () {
    Mail::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'valid_until' => now()->subDay(),
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $balanceSync = Mockery::mock(BalanceSyncService::class);

    $job = new SyncBankingConnectionJob($connection);
    runSync($job, $transactionSync, $balanceSync);

    $connection->refresh();
    $log = BankingSyncLog::where('banking_connection_id', $connection->id)->first();
    expect($connection->status)->toBe(BankingConnectionStatus::Expired);
    expect($log)->not->toBeNull();
    expect($log->status)->toBe(BankingSyncLogStatus::Skipped);
    expect($log->metadata)->toBe(['reason' => 'expired']);

    Mail::assertQueued(BankingConnectionExpiredEmail::class, function (BankingConnectionExpiredEmail $mail) use ($user, $connection) {
        return $mail->user->is($user)
            && $mail->bankingConnection->is($connection);
    });
});

test('skipped sync creates a skipped log entry for non-syncable status', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $balanceSync = Mockery::mock(BalanceSyncService::class);

    $job = new SyncBankingConnectionJob($connection);
    runSync($job, $transactionSync, $balanceSync);

    $log = BankingSyncLog::where('banking_connection_id', $connection->id)->first();
    expect($log)->not->toBeNull();
    expect($log->status)->toBe(BankingSyncLogStatus::Skipped);
    expect($log->metadata)->toMatchArray(['reason' => 'not_syncable']);
});

test('rate limit error creates a failed log entry', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andThrow(
        new RequestException(
            new Illuminate\Http\Client\Response(new Response(429))
        )
    );

    $balanceSync = Mockery::mock(BalanceSyncService::class);

    $job = new SyncBankingConnectionJob($connection);
    runSync($job, $transactionSync, $balanceSync);

    $log = BankingSyncLog::where('banking_connection_id', $connection->id)->first();
    expect($log)->not->toBeNull();
    expect($log->status)->toBe(BankingSyncLogStatus::Failed);
});

test('sync log records attempt number', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andThrow(
        new RequestException(
            new Illuminate\Http\Client\Response(new Response(500))
        )
    );

    $balanceSync = Mockery::mock(BalanceSyncService::class);

    // Simulate the second attempt
    $job = new SyncBankingConnectionJob($connection);
    $job->job = Mockery::mock(Job::class);
    $job->job->shouldReceive('attempts')->andReturn(2);
    $job->job->shouldReceive('isReleased')->andReturn(false);
    $job->job->shouldReceive('isDeletedOrReleased')->andReturn(false);
    $job->job->shouldReceive('hasFailed')->andReturn(false);

    try {
        runSync($job, $transactionSync, $balanceSync);
    } catch (RequestException) {
        // Expected
    }

    $log = BankingSyncLog::where('banking_connection_id', $connection->id)->first();
    expect($log->attempt)->toBe(2);
});

test('connection sync logs relationship works', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->once()->andReturn(0);

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->once();

    $job = new SyncBankingConnectionJob($connection);
    runSync($job, $transactionSync, $balanceSync);

    expect($connection->syncLogs()->count())->toBe(1);
    expect($connection->latestSyncLog)->not->toBeNull();
    expect($connection->latestSyncLog->status)->toBe(BankingSyncLogStatus::Success);
});

// --- SyncAllBankingConnectionsJob Retry Tests ---

test('scheduled sync includes error connections under retry cap', function () {
    Queue::fake(SyncBankingConnectionJob::class);

    $user = User::factory()->onboarded()->create();

    $activeConnection = BankingConnection::factory()->create([
        'user_id' => $user->id,
    ]);

    $errorConnection = BankingConnection::factory()->error()->create([
        'user_id' => $user->id,
        'consecutive_sync_failures' => 1,
    ]);

    $job = new SyncAllBankingConnectionsJob;
    $job->handle();

    Queue::assertPushed(SyncBankingConnectionJob::class, 2);
    Queue::assertPushed(SyncBankingConnectionJob::class, fn ($job) => $job->bankingConnection->id === $activeConnection->id);
    Queue::assertPushed(SyncBankingConnectionJob::class, fn ($job) => $job->bankingConnection->id === $errorConnection->id);
});

test('scheduled sync excludes error connections at or over retry cap', function () {
    Queue::fake(SyncBankingConnectionJob::class);

    $user = User::factory()->onboarded()->create();

    $activeConnection = BankingConnection::factory()->create([
        'user_id' => $user->id,
    ]);

    // At the cap - should be excluded
    BankingConnection::factory()->error()->create([
        'user_id' => $user->id,
        'consecutive_sync_failures' => SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES,
    ]);

    // Over the cap (auth error) - should be excluded
    BankingConnection::factory()->error()->create([
        'user_id' => $user->id,
        'consecutive_sync_failures' => SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES + 1,
    ]);

    $job = new SyncAllBankingConnectionsJob;
    $job->handle();

    Queue::assertPushed(SyncBankingConnectionJob::class, 1);
    Queue::assertPushed(SyncBankingConnectionJob::class, fn ($job) => $job->bankingConnection->id === $activeConnection->id);
});

test('scheduled sync excludes error connections with expired valid_until', function () {
    Queue::fake(SyncBankingConnectionJob::class);

    $user = User::factory()->onboarded()->create();

    BankingConnection::factory()->error()->create([
        'user_id' => $user->id,
        'consecutive_sync_failures' => 1,
        'valid_until' => now()->subDay(),
    ]);

    $job = new SyncAllBankingConnectionsJob;
    $job->handle();

    Queue::assertNotPushed(SyncBankingConnectionJob::class);
});

test('scheduled sync includes active enablebanking connections whose valid_until expired', function () {
    Queue::fake(SyncBankingConnectionJob::class);

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'status' => BankingConnectionStatus::Active,
        'valid_until' => now()->subDay(),
    ]);

    $job = new SyncAllBankingConnectionsJob;
    $job->handle();

    Queue::assertPushed(SyncBankingConnectionJob::class, 1);
    Queue::assertPushed(SyncBankingConnectionJob::class, fn ($job) => $job->bankingConnection->id === $connection->id);
});

test('scheduled sync skips connections still within rate limit backoff window', function () {
    Queue::fake(SyncBankingConnectionJob::class);

    $user = User::factory()->onboarded()->create();

    BankingConnection::factory()->create([
        'user_id' => $user->id,
        'rate_limited_until' => now()->addHour(),
    ]);

    $eligible = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'rate_limited_until' => now()->subMinute(),
    ]);

    $job = new SyncAllBankingConnectionsJob;
    $job->handle();

    Queue::assertPushed(SyncBankingConnectionJob::class, 1);
    Queue::assertPushed(SyncBankingConnectionJob::class, fn ($job) => $job->bankingConnection->id === $eligible->id);
});

// --- Manual Retry Reset Tests ---

test('manual sync resets consecutive sync failures', function () {
    $user = User::factory()->onboarded()->create();

    $connection = BankingConnection::factory()->error()->create([
        'user_id' => $user->id,
        'consecutive_sync_failures' => 2,
    ]);

    Queue::fake(SyncBankingConnectionJob::class);

    $this->actingAs($user)
        ->post(route('settings.connections.sync', $connection))
        ->assertRedirect();

    $connection->refresh();
    expect($connection->consecutive_sync_failures)->toBe(0);
    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->error_message)->toBeNull();
});

test('manual sync is refused under a live backoff rather than silently swallowed', function () {
    $user = User::factory()->onboarded()->create();

    // Manual sync used to clear the window, on the theory that a person asking for
    // their own connection is a different request from the scheduler's. On a bank
    // with a small per-consent allowance that only bought another refusal and burnt
    // the scheduled run, so the request is now declined out loud instead - which
    // answers the same complaint the other way: the UI no longer says "sync
    // started" while the job returns early and nothing happens.
    $until = now()->addHours(20);
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'rate_limited_until' => $until,
    ]);

    Queue::fake(SyncBankingConnectionJob::class);

    $this->actingAs($user)
        ->post(route('settings.connections.sync', $connection))
        ->assertRedirect()
        ->assertSessionHas('error');

    $connection->refresh();
    expect($connection->rate_limited_until->toDateTimeString())->toBe($until->toDateTimeString());
    Queue::assertNotPushed(SyncBankingConnectionJob::class);
});

// --- Job-Level Failure Tests ---

test('a job killed by the worker timeout does not spend a scheduled retry', function () {
    $user = User::factory()->onboarded()->create();
    // The reachable state: every incrementer also writes Error, so an Active
    // connection carries 0 unless a reconnect left a stale count behind.
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'consecutive_sync_failures' => 0,
    ]);

    // The worker's timeout never reaches handle()'s catch, so failed() is the
    // only place the connection hears about it.
    (new SyncBankingConnectionJob($connection))->failed(
        new TimeoutExceededException('App\Jobs\SyncBankingConnectionJob has timed out.')
    );

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Error);
    expect($connection->error_message)->not->toBeNull();
    expect($connection->consecutive_sync_failures)->toBe(0);
});

test('a reconnect that left a stale count is not pushed over the ceiling by a job death', function () {
    $user = User::factory()->onboarded()->create();
    // AuthorizationController used to return a connection to Active without
    // clearing the counter, which is the only way this pairing arises - and the
    // only route by which failed() could ever reach the ceiling.
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'consecutive_sync_failures' => SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES - 1,
    ]);

    (new SyncBankingConnectionJob($connection))->failed(
        new TimeoutExceededException('App\Jobs\SyncBankingConnectionJob has timed out.')
    );

    $connection->refresh();
    expect($connection->consecutive_sync_failures)
        ->toBe(SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES - 1);
});

test('a second out-of-band death on an already errored connection changes nothing', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->error()->create([
        'user_id' => $user->id,
        'consecutive_sync_failures' => 2,
        'error_message' => 'Earlier failure kept.',
    ]);

    (new SyncBankingConnectionJob($connection))->failed(
        new TimeoutExceededException('App\Jobs\SyncBankingConnectionJob has timed out.')
    );

    // The guard leaves the connection itself untouched. This is the fact that
    // caps the counter at one increment per lifetime, so it is worth pinning down.
    $connection->refresh();
    expect($connection->consecutive_sync_failures)->toBe(2);
    expect($connection->error_message)->toBe('Earlier failure kept.');

    // The death is still recorded. A connection parked in Error is the state that
    // repeats - it is where the 65 unrecorded deaths of the connection this was
    // written for happened - so dropping the log here would drop the whole point.
    $log = BankingSyncLog::where('banking_connection_id', $connection->id)->sole();
    expect($log->metadata['reason'])->toBe('job_died_outside_handle');
});

test('an out-of-band job death is recorded in the connection history', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);

    (new SyncBankingConnectionJob($connection))->failed(
        new TimeoutExceededException('App\Jobs\SyncBankingConnectionJob has timed out.')
    );

    $log = BankingSyncLog::where('banking_connection_id', $connection->id)->sole();
    expect($log->status)->toBe(BankingSyncLogStatus::Failed);
    expect($log->error_class)->toBe(TimeoutExceededException::class);
    expect($log->metadata['reason'])->toBe('job_died_outside_handle');
    // Unknown rather than zero: nobody timed this attempt.
    expect($log->duration_ms)->toBeNull();
});
