<?php

use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\Banking\BinanceBalanceSyncService;
use App\Services\Banking\BinanceClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
});

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
        'api.binance.com/sapi/v1/accountSnapshot*' => Http::response(['snapshotVos' => []]),
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
    $service = app(BinanceBalanceSyncService::class);
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
        'api.binance.com/sapi/v1/accountSnapshot*' => Http::response(['snapshotVos' => []]),
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
    $service = app(BinanceBalanceSyncService::class);
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
        'api.binance.com/sapi/v1/accountSnapshot*' => Http::response(['snapshotVos' => []]),
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'USDT', 'free' => '500.00', 'locked' => '0.0'],
                ['asset' => 'USDC', 'free' => '300.00', 'locked' => '0.0'],
            ],
        ]),
        'api.binance.com/api/v3/ticker/price' => Http::response([]),
    ]);

    $client = new BinanceClient('test-key', 'test-secret');
    $service = app(BinanceBalanceSyncService::class);
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
        'api.binance.com/sapi/v1/accountSnapshot*' => Http::response(['snapshotVos' => []]),
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
    $service = app(BinanceBalanceSyncService::class);
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
        'api.binance.com/sapi/v1/accountSnapshot*' => Http::response(['snapshotVos' => []]),
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
    $service = app(BinanceBalanceSyncService::class);
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
        'api.binance.com/sapi/v1/accountSnapshot*' => Http::response(['snapshotVos' => []]),
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [],
        ]),
        'api.binance.com/api/v3/ticker/price' => Http::response([]),
    ]);

    $client = new BinanceClient('test-key', 'test-secret');
    $service = app(BinanceBalanceSyncService::class);
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

    $service = app(BinanceBalanceSyncService::class);
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(0);
});

test('first sync fetches historical snapshots and converts using currency API', function () {
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

    $yesterday = now()->subDay();
    $twoDaysAgo = now()->subDays(2);

    Http::fake([
        'api.binance.com/sapi/v1/accountSnapshot*' => Http::response([
            'snapshotVos' => [
                [
                    'type' => 'spot',
                    'updateTime' => $twoDaysAgo->getTimestampMs(),
                    'data' => [
                        'balances' => [
                            ['asset' => 'BTC', 'free' => '2.0', 'locked' => '0.0'],
                        ],
                    ],
                ],
                [
                    'type' => 'spot',
                    'updateTime' => $yesterday->getTimestampMs(),
                    'data' => [
                        'balances' => [
                            ['asset' => 'BTC', 'free' => '2.0', 'locked' => '0.0'],
                        ],
                    ],
                ],
            ],
        ]),
        'cdn.jsdelivr.net/*currencies/eur*' => Http::response([
            'eur' => [
                'btc' => 0.000019, // 1 EUR = 0.000019 BTC → 1 BTC = 52631.58 EUR
            ],
        ]),
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'BTC', 'free' => '2.0', 'locked' => '0.0'],
            ],
        ]),
        'api.binance.com/api/v3/ticker/price' => Http::response([
            ['symbol' => 'BTCUSDT', 'price' => '56100.00'],
            ['symbol' => 'EURUSDT', 'price' => '1.10'],
        ]),
    ]);

    $client = new BinanceClient('test-key', 'test-secret');
    $service = app(BinanceBalanceSyncService::class);
    $service->sync($account, $client, isFirstSync: true);

    // 2 historical days + 1 current day = 3
    expect($account->balances()->count())->toBe(3);

    // Historical: 2 BTC / 0.000019 = 105263.16 EUR → 10526316 cents
    $oldBalance = $account->balances()->where('balance_date', $twoDaysAgo->toDateString())->first();
    expect($oldBalance->balance)->toBe(10526316);

    $yesterdayBalance = $account->balances()->where('balance_date', $yesterday->toDateString())->first();
    expect($yesterdayBalance->balance)->toBe(10526316);

    // Current (ticker-based): 2 BTC * 56100 USDT / 1.10 = 102000 EUR
    $todayBalance = $account->balances()->where('balance_date', now()->toDateString())->first();
    expect($todayBalance->balance)->toBe(10200000);
});

test('subsequent sync only fetches snapshots since last balance date', function () {
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

    // Pre-existing balance from 2 days ago — subsequent sync should start from yesterday
    $account->balances()->create([
        'balance_date' => now()->subDays(2)->toDateString(),
        'balance' => 5000000,
    ]);

    $yesterday = now()->subDay();

    Http::fake([
        'api.binance.com/sapi/v1/accountSnapshot*' => Http::response([
            'snapshotVos' => [
                [
                    'type' => 'spot',
                    'updateTime' => $yesterday->getTimestampMs(),
                    'data' => [
                        'balances' => [
                            ['asset' => 'BTC', 'free' => '1.0', 'locked' => '0.0'],
                        ],
                    ],
                ],
            ],
        ]),
        'cdn.jsdelivr.net/*currencies/eur*' => Http::response([
            'eur' => [
                'btc' => 0.000018, // 1 BTC = 1/0.000018 = 55555.56 EUR
            ],
        ]),
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'BTC', 'free' => '1.0', 'locked' => '0.0'],
            ],
        ]),
        'api.binance.com/api/v3/ticker/price' => Http::response([
            ['symbol' => 'BTCUSDT', 'price' => '61600.00'],
            ['symbol' => 'EURUSDT', 'price' => '1.10'],
        ]),
    ]);

    $client = new BinanceClient('test-key', 'test-secret');
    $service = app(BinanceBalanceSyncService::class);
    $service->sync($account, $client, isFirstSync: false);

    // 1 pre-existing + 1 historical (yesterday) + 1 current (today) = 3
    expect($account->balances()->count())->toBe(3);

    // Historical: 1 BTC / 0.000018 = 55555.56 EUR → 5555556 cents
    $yesterdayBalance = $account->balances()->where('balance_date', $yesterday->toDateString())->first();
    expect($yesterdayBalance->balance)->toBe(5555556);

    // Current (ticker-based): 1 BTC * 61600 USDT / 1.10 = 56000 EUR
    $todayBalance = $account->balances()->where('balance_date', now()->toDateString())->first();
    expect($todayBalance->balance)->toBe(5600000);
});

test('historical sync converts assets using currency API', function () {
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

    $yesterday = now()->subDay();

    Http::fake([
        'api.binance.com/sapi/v1/accountSnapshot*' => Http::response([
            'snapshotVos' => [
                [
                    'type' => 'spot',
                    'updateTime' => $yesterday->getTimestampMs(),
                    'data' => [
                        'balances' => [
                            ['asset' => 'SOL', 'free' => '10.0', 'locked' => '0.0'],
                        ],
                    ],
                ],
            ],
        ]),
        'cdn.jsdelivr.net/*currencies/eur*' => Http::response([
            'eur' => [
                'sol' => 0.01, // 1 EUR = 0.01 SOL → 1 SOL = 100 EUR
            ],
        ]),
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'SOL', 'free' => '10.0', 'locked' => '0.0'],
            ],
        ]),
        'api.binance.com/api/v3/ticker/price' => Http::response([
            ['symbol' => 'SOLUSDT', 'price' => '105.00'],
            ['symbol' => 'EURUSDT', 'price' => '1.10'],
        ]),
    ]);

    $client = new BinanceClient('test-key', 'test-secret');
    $service = app(BinanceBalanceSyncService::class);
    $service->sync($account, $client, isFirstSync: true);

    // Historical: 10 SOL / 0.01 = 1000 EUR → 100000 cents
    $yesterdayBalance = $account->balances()->where('balance_date', $yesterday->toDateString())->first();
    expect($yesterdayBalance->balance)->toBe(100000);
});
