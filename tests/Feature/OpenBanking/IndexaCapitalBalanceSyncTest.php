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

test('stores invested_amount from net_amounts data', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->indexaCapital()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'IC-001',
    ]);

    $today = now()->toDateString();
    $yesterday = now()->subDay()->toDateString();
    $todayKey = str_replace('-', '', $today);
    $yesterdayKey = str_replace('-', '', $yesterday);

    Http::fake([
        'api.indexacapital.com/accounts/IC-001/performance' => Http::response([
            'portfolios' => [
                [
                    'date' => $today,
                    'total_amount' => 15000.00,
                    'instruments_cost' => 12000.00,
                    'instruments_amount' => 14700.00,
                    'cash_amount' => 300.00,
                ],
                [
                    'date' => $yesterday,
                    'total_amount' => 14500.00,
                    'instruments_cost' => 12000.00,
                    'instruments_amount' => 14200.00,
                    'cash_amount' => 300.00,
                ],
            ],
            'net_amounts' => [
                $todayKey => 11000.00,
                $yesterdayKey => 10800.00,
            ],
        ]),
    ]);

    $client = new IndexaCapitalClient('test-token');
    $service = new IndexaCapitalBalanceSyncService;
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(2);

    $latest = $account->balances()->orderBy('balance_date', 'desc')->first();
    // invested_amount comes from net_amounts, not instruments_cost + cash_amount
    expect($latest->balance)->toBe(1500000);
    expect($latest->invested_amount)->toBe(1100000);

    $previous = $account->balances()->orderBy('balance_date', 'asc')->first();
    expect($previous->invested_amount)->toBe(1080000);
});

test('stores null invested_amount when net_amounts is missing', function () {
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
                ['date' => now()->toDateString(), 'total_amount' => 15000.00],
            ],
        ]),
    ]);

    $client = new IndexaCapitalClient('test-token');
    $service = new IndexaCapitalBalanceSyncService;
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(1);

    $balance = $account->balances()->first();
    expect($balance->balance)->toBe(1500000);
    expect($balance->invested_amount)->toBeNull();
});

test('falls back to total_amount minus return when net_amounts is missing', function () {
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
                // No net_amounts in response, but entry has return field
                ['date' => now()->toDateString(), 'total_amount' => 8000.00, 'return' => -500.00],
            ],
        ]),
    ]);

    $client = new IndexaCapitalClient('test-token');
    $service = new IndexaCapitalBalanceSyncService;
    $service->sync($account, $client);

    $balance = $account->balances()->first();
    expect($balance->balance)->toBe(800000);
    // invested_amount = total_amount - return = 8000 - (-500) = 8500 → 850000 cents
    expect($balance->invested_amount)->toBe(850000);
});

test('subsequent sync only processes entries since last balance date', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->indexaCapital()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'IC-001',
    ]);

    // Simulate existing balances from a previous full sync
    $account->balances()->create([
        'balance_date' => now()->subDays(5)->toDateString(),
        'balance' => 1400000,
    ]);
    $account->balances()->create([
        'balance_date' => now()->subDays(4)->toDateString(),
        'balance' => 1410000,
    ]);

    Http::fake([
        'api.indexacapital.com/accounts/IC-001/performance' => Http::response([
            'portfolios' => [
                // Old entries that should be skipped
                ['date' => now()->subDays(10)->toDateString(), 'total_amount' => 13000.00],
                ['date' => now()->subDays(6)->toDateString(), 'total_amount' => 13900.00],
                // Entry on the last balance date (should be processed — updated)
                ['date' => now()->subDays(4)->toDateString(), 'total_amount' => 14200.00],
                // New entries
                ['date' => now()->subDays(3)->toDateString(), 'total_amount' => 14500.00],
                ['date' => now()->subDays(2)->toDateString(), 'total_amount' => 14800.00],
                ['date' => now()->toDateString(), 'total_amount' => 15000.00],
            ],
        ]),
    ]);

    $client = new IndexaCapitalClient('test-token');
    $service = new IndexaCapitalBalanceSyncService;
    $service->sync($account, $client, isFirstSync: false);

    // 2 pre-existing + 3 new entries = 5 total (the one on the boundary date gets updated, not duplicated)
    expect($account->balances()->count())->toBe(5);

    // Verify old entry was NOT overwritten (the one from subDays(5) should still be there)
    $oldBalance = $account->balances()->where('balance_date', now()->subDays(5)->toDateString())->first();
    expect($oldBalance->balance)->toBe(1400000);

    // Verify boundary entry was updated
    $boundaryBalance = $account->balances()->where('balance_date', now()->subDays(4)->toDateString())->first();
    expect($boundaryBalance->balance)->toBe(1420000);

    // Verify new entry was created
    $newBalance = $account->balances()->where('balance_date', now()->toDateString())->first();
    expect($newBalance->balance)->toBe(1500000);
});

test('full sync processes all entries regardless of existing balances', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->indexaCapital()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'IC-001',
    ]);

    // Simulate existing balances from a previous sync
    $account->balances()->create([
        'balance_date' => now()->subDays(2)->toDateString(),
        'balance' => 1400000,
    ]);

    Http::fake([
        'api.indexacapital.com/accounts/IC-001/performance' => Http::response([
            'portfolios' => [
                ['date' => now()->subDays(5)->toDateString(), 'total_amount' => 13000.00],
                ['date' => now()->subDays(2)->toDateString(), 'total_amount' => 14000.00],
                ['date' => now()->toDateString(), 'total_amount' => 15000.00],
            ],
        ]),
    ]);

    $client = new IndexaCapitalClient('test-token');
    $service = new IndexaCapitalBalanceSyncService;
    $service->sync($account, $client, isFirstSync: true);

    // All 3 entries processed (1 existing updated + 2 new)
    expect($account->balances()->count())->toBe(3);

    // The old entry at subDays(2) should be updated with new value
    $updatedBalance = $account->balances()->where('balance_date', now()->subDays(2)->toDateString())->first();
    expect($updatedBalance->balance)->toBe(1400000);
});
