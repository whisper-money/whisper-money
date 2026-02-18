<?php

use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\Banking\IndexaCapitalBalanceSyncService;
use App\Services\Banking\IndexaCapitalClient;
use Illuminate\Support\Facades\Http;

test('syncs balance from indexa capital performance data', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->indexaCapital()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'IC-001',
    ]);

    Http::fake([
        'api.indexacapital.com/accounts/IC-001/performance' => Http::response([
            'total_amount' => 15234.56,
            'return' => 1234.56,
            'return_percentage' => 8.82,
        ]),
    ]);

    $client = new IndexaCapitalClient('test-token');
    $service = new IndexaCapitalBalanceSyncService;
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(1);

    $balance = $account->balances()->first();
    expect($balance->balance)->toBe(1523456);
    expect($balance->balance_date->toDateString())->toBe(now()->toDateString());
});

test('updates existing balance for same date', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->indexaCapital()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'IC-001',
    ]);

    $account->balances()->create([
        'balance_date' => now()->toDateString(),
        'balance' => 100000,
    ]);

    Http::fake([
        'api.indexacapital.com/accounts/IC-001/performance' => Http::response([
            'total_amount' => 20000.00,
        ]),
    ]);

    $client = new IndexaCapitalClient('test-token');
    $service = new IndexaCapitalBalanceSyncService;
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(1);
    expect($account->balances()->first()->balance)->toBe(2000000);
});

test('skips account without external_account_id', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'external_account_id' => null,
    ]);

    $client = Mockery::mock(IndexaCapitalClient::class);
    $client->shouldNotReceive('getPerformance');

    $service = new IndexaCapitalBalanceSyncService;
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(0);
});

test('handles missing value field gracefully', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->indexaCapital()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'IC-001',
    ]);

    Http::fake([
        'api.indexacapital.com/accounts/IC-001/performance' => Http::response([
            'some_unknown_field' => 12345,
        ]),
    ]);

    $client = new IndexaCapitalClient('test-token');
    $service = new IndexaCapitalBalanceSyncService;
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(0);
});
