<?php

use App\Enums\BankingConnectionStatus;
use App\Jobs\SyncBinanceHistoricalBalancesJob;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\Banking\BinanceBalanceSyncService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
});

test('job syncs historical balances via the service', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->binance()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'binance-portfolio',
    ]);

    $syncService = Mockery::mock(BinanceBalanceSyncService::class);
    $syncService->shouldReceive('syncHistoricalBalances')
        ->once()
        ->withArgs(function ($acct, $client, $isFirstSync) use ($account) {
            return $acct->id === $account->id && $isFirstSync === true;
        })
        ->andReturn(true);

    $job = new SyncBinanceHistoricalBalancesJob($account);
    $job->handle($syncService);
});

test('job does nothing if account has no banking connection', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'banking_connection_id' => null,
    ]);

    $syncService = Mockery::mock(BinanceBalanceSyncService::class);
    $syncService->shouldNotReceive('syncHistoricalBalances');

    $job = new SyncBinanceHistoricalBalancesJob($account);
    $job->handle($syncService);
});

test('job does nothing if connection is not binance', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
    ]);

    $syncService = Mockery::mock(BinanceBalanceSyncService::class);
    $syncService->shouldNotReceive('syncHistoricalBalances');

    $job = new SyncBinanceHistoricalBalancesJob($account);
    $job->handle($syncService);
});

test('failed method logs error but does not update connection status', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->binance()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
    ]);

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $context) use ($account) {
            return $message === 'Binance historical balance sync failed'
                && $context['account_id'] === $account->id;
        });

    $job = new SyncBinanceHistoricalBalancesJob($account);
    $job->failed(new RuntimeException('API rate limit'));

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);
});

test('uniqueId returns account-based identifier', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
    ]);

    $job = new SyncBinanceHistoricalBalancesJob($account);

    expect($job->uniqueId())->toBe('binance-historical-'.$account->id);
});
