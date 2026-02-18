<?php

use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\Banking\IndexaCapitalBalanceSyncService;
use App\Services\Banking\IndexaCapitalClient;
use Illuminate\Support\Facades\Http;

test('syncs historical balances from indexa capital portfolios', function () {
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
            'portfolios' => [
                ['date' => now()->toDateString(), 'total_amount' => 15234.56],
                ['date' => now()->subDay()->toDateString(), 'total_amount' => 15200.00],
                ['date' => now()->subDays(2)->toDateString(), 'total_amount' => 15100.00],
            ],
        ]),
    ]);

    $client = new IndexaCapitalClient('test-token');
    $service = new IndexaCapitalBalanceSyncService;
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(3);

    $latest = $account->balances()->orderBy('balance_date', 'desc')->first();
    expect($latest->balance)->toBe(1523456);
    expect($latest->balance_date->toDateString())->toBe(now()->toDateString());
});

test('syncs all available historical data', function () {
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
            'portfolios' => [
                ['date' => now()->toDateString(), 'total_amount' => 10000.00],
                ['date' => now()->subMonths(12)->toDateString(), 'total_amount' => 9000.00],
                ['date' => now()->subYears(3)->toDateString(), 'total_amount' => 8000.00],
                ['date' => now()->subYears(5)->toDateString(), 'total_amount' => 7000.00],
            ],
        ]),
    ]);

    $client = new IndexaCapitalClient('test-token');
    $service = new IndexaCapitalBalanceSyncService;
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(4);
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
            'portfolios' => [
                ['date' => now()->toDateString(), 'total_amount' => 20000.00],
            ],
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

test('handles missing portfolios gracefully', function () {
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
            'plan_expected_return' => 0.046,
        ]),
    ]);

    $client = new IndexaCapitalClient('test-token');
    $service = new IndexaCapitalBalanceSyncService;
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(0);
});

test('handles empty portfolios array gracefully', function () {
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
            'portfolios' => [],
        ]),
    ]);

    $client = new IndexaCapitalClient('test-token');
    $service = new IndexaCapitalBalanceSyncService;
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(0);
});
