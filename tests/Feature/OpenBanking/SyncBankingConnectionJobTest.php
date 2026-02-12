<?php

use App\Jobs\SyncBankingConnectionJob;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Banking\BalanceSyncService;
use App\Services\Banking\TransactionSyncService;

test('first sync calculates historical balances', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => null,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->once();

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->once();
    $balanceSync->shouldReceive('calculateHistoricalBalances')->once();

    $job = new SyncBankingConnectionJob($connection);
    $job->handle($transactionSync, $balanceSync);
});

test('subsequent syncs do not calculate historical balances', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->once();

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->once();
    $balanceSync->shouldNotReceive('calculateHistoricalBalances');

    $job = new SyncBankingConnectionJob($connection);
    $job->handle($transactionSync, $balanceSync);
});

test('linked accounts sync from last transaction date and skip historical balances', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => null,
    ]);
    $account = Account::factory()->linked()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2025-12-15',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')
        ->once()
        ->withArgs(function ($acct, $dateFrom, $dateTo, $strategy) {
            return $dateFrom === '2025-12-15';
        });

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->once();
    $balanceSync->shouldNotReceive('calculateHistoricalBalances');

    $job = new SyncBankingConnectionJob($connection);
    $job->handle($transactionSync, $balanceSync);
});

test('mixed linked and new accounts in same connection', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => null,
    ]);

    $newAccount = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-new',
    ]);

    $linkedAccount = Account::factory()->linked()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-linked',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->twice();

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->twice();
    $balanceSync->shouldReceive('calculateHistoricalBalances')
        ->once()
        ->with(Mockery::on(fn ($acct) => $acct->id === $newAccount->id));

    $job = new SyncBankingConnectionJob($connection);
    $job->handle($transactionSync, $balanceSync);
});
