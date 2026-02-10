<?php

use App\Jobs\SyncBankingConnectionJob;
use App\Models\Account;
use App\Models\BankingConnection;
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
