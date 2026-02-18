<?php

use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\Banking\BinanceBalanceSyncService;
use App\Services\Banking\BinanceClient;
use Illuminate\Support\Facades\Http;

test('syncs binance balance using direct EUR pair', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->binance()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'binance-portfolio',
        'currency_code' => 'EUR',
    ]);

    Http::fake([
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'BTC', 'free' => '1.0', 'locked' => '0.0'],
            ],
        ]),
        'api.binance.com/api/v3/ticker/price' => Http::response([
            ['symbol' => 'BTCEUR', 'price' => '50000.00'],
            ['symbol' => 'BTCUSDT', 'price' => '52000.00'],
        ]),
    ]);

    $client = new BinanceClient('test-key', 'test-secret');
    $service = new BinanceBalanceSyncService;
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(1);

    $balance = $account->balances()->first();
    expect($balance->balance)->toBe(5000000); // 50000.00 EUR * 100
    expect($balance->balance_date->toDateString())->toBe(now()->toDateString());
});

test('syncs binance balance using USDT fallback conversion', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->binance()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'binance-portfolio',
        'currency_code' => 'EUR',
    ]);

    Http::fake([
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'SOL', 'free' => '10.0', 'locked' => '0.0'],
            ],
        ]),
        'api.binance.com/api/v3/ticker/price' => Http::response([
            ['symbol' => 'SOLUSDT', 'price' => '100.00'],
            ['symbol' => 'EURUSDT', 'price' => '1.10'],
        ]),
    ]);

    $client = new BinanceClient('test-key', 'test-secret');
    $service = new BinanceBalanceSyncService;
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(1);

    // 10 SOL * 100 USDT = 1000 USDT / 1.10 EUR/USDT = ~909.09 EUR
    $balance = $account->balances()->first();
    expect($balance->balance)->toBe(90909); // 909.09 EUR * 100
});

test('handles USD stablecoins as 1:1 when target is USD', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'USD']);
    $connection = BankingConnection::factory()->binance()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'binance-portfolio',
        'currency_code' => 'USD',
    ]);

    Http::fake([
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'USDT', 'free' => '500.00', 'locked' => '0.0'],
                ['asset' => 'USDC', 'free' => '300.00', 'locked' => '0.0'],
            ],
        ]),
        'api.binance.com/api/v3/ticker/price' => Http::response([]),
    ]);

    $client = new BinanceClient('test-key', 'test-secret');
    $service = new BinanceBalanceSyncService;
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(1);

    $balance = $account->balances()->first();
    expect($balance->balance)->toBe(80000); // (500 + 300) * 100
});

test('includes locked balances in total', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->binance()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'binance-portfolio',
        'currency_code' => 'EUR',
    ]);

    Http::fake([
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'BTC', 'free' => '0.5', 'locked' => '0.5'],
            ],
        ]),
        'api.binance.com/api/v3/ticker/price' => Http::response([
            ['symbol' => 'BTCEUR', 'price' => '50000.00'],
        ]),
    ]);

    $client = new BinanceClient('test-key', 'test-secret');
    $service = new BinanceBalanceSyncService;
    $service->sync($account, $client);

    $balance = $account->balances()->first();
    expect($balance->balance)->toBe(5000000); // (0.5 + 0.5) * 50000 * 100
});

test('updates existing balance for same date', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->binance()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'binance-portfolio',
        'currency_code' => 'EUR',
    ]);

    $account->balances()->create([
        'balance_date' => now()->toDateString(),
        'balance' => 100000,
    ]);

    Http::fake([
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'BTC', 'free' => '1.0', 'locked' => '0.0'],
            ],
        ]),
        'api.binance.com/api/v3/ticker/price' => Http::response([
            ['symbol' => 'BTCEUR', 'price' => '60000.00'],
        ]),
    ]);

    $client = new BinanceClient('test-key', 'test-secret');
    $service = new BinanceBalanceSyncService;
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(1);
    expect($account->balances()->first()->balance)->toBe(6000000);
});

test('handles empty balances gracefully', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->binance()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'binance-portfolio',
        'currency_code' => 'EUR',
    ]);

    Http::fake([
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [],
        ]),
        'api.binance.com/api/v3/ticker/price' => Http::response([]),
    ]);

    $client = new BinanceClient('test-key', 'test-secret');
    $service = new BinanceBalanceSyncService;
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(0);
});

test('skips account without external_account_id', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'external_account_id' => null,
    ]);

    $client = Mockery::mock(BinanceClient::class);
    $client->shouldNotReceive('getAccount');

    $service = new BinanceBalanceSyncService;
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(0);
});
