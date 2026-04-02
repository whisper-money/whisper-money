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
        'api.binance.com/sapi/v1/capital/deposit/hisrec*' => Http::response([]),
        'api.binance.com/sapi/v1/capital/withdraw/history*' => Http::response([]),
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
        'api.binance.com/sapi/v1/capital/deposit/hisrec*' => Http::response([]),
        'api.binance.com/sapi/v1/capital/withdraw/history*' => Http::response([]),
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

test('syncs binance balance for unsupported quote currencies via usd conversion', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'ARS']);
    $connection = BankingConnection::factory()->binance()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'binance-portfolio',
        'currency_code' => 'ARS',
    ]);

    Http::fake([
        'api.binance.com/sapi/v1/capital/deposit/hisrec*' => Http::response([]),
        'api.binance.com/sapi/v1/capital/withdraw/history*' => Http::response([]),
        'api.binance.com/sapi/v1/accountSnapshot*' => Http::response(['snapshotVos' => []]),
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'SOL', 'free' => '10.0', 'locked' => '0.0'],
            ],
        ]),
        'api.binance.com/api/v3/ticker/price' => Http::response([
            ['symbol' => 'SOLUSDT', 'price' => '100.00'],
        ]),
        'cdn.jsdelivr.net/*currencies/ars*' => Http::response([
            'ars' => [
                'usd' => 0.0007142857,
            ],
        ]),
    ]);

    $client = new BinanceClient('test-key', 'test-secret');
    $service = app(BinanceBalanceSyncService::class);
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(1);

    $balance = $account->balances()->first();
    expect($balance->balance)->toBe((int) round((1000 / 0.0007142857) * 100));
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
        'api.binance.com/sapi/v1/capital/deposit/hisrec*' => Http::response([]),
        'api.binance.com/sapi/v1/capital/withdraw/history*' => Http::response([]),
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
        'api.binance.com/sapi/v1/capital/deposit/hisrec*' => Http::response([]),
        'api.binance.com/sapi/v1/capital/withdraw/history*' => Http::response([]),
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
        'api.binance.com/sapi/v1/capital/deposit/hisrec*' => Http::response([]),
        'api.binance.com/sapi/v1/capital/withdraw/history*' => Http::response([]),
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
        'api.binance.com/sapi/v1/capital/deposit/hisrec*' => Http::response([]),
        'api.binance.com/sapi/v1/capital/withdraw/history*' => Http::response([]),
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
        'api.binance.com/sapi/v1/capital/deposit/hisrec*' => Http::response([]),
        'api.binance.com/sapi/v1/capital/withdraw/history*' => Http::response([]),
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
        'api.binance.com/sapi/v1/capital/deposit/hisrec*' => Http::response([]),
        'api.binance.com/sapi/v1/capital/withdraw/history*' => Http::response([]),
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
        'api.binance.com/sapi/v1/capital/deposit/hisrec*' => Http::response([]),
        'api.binance.com/sapi/v1/capital/withdraw/history*' => Http::response([]),
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

test('calculates invested_amount from deposit and withdrawal history', function () {
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
        'api.binance.com/sapi/v1/capital/deposit/hisrec*' => Http::sequence()
            ->push([
                [
                    'id' => 'dep-1',
                    'amount' => '0.5',
                    'coin' => 'BTC',
                    'status' => 1,
                    'transferType' => 0,
                    'insertTime' => now()->subDays(30)->getTimestampMs(),
                ],
                [
                    'id' => 'dep-2',
                    'amount' => '1000.00',
                    'coin' => 'USDT',
                    'status' => 1,
                    'transferType' => 0,
                    'insertTime' => now()->subDays(20)->getTimestampMs(),
                ],
            ])
            ->whenEmpty(Http::response([])),
        'api.binance.com/sapi/v1/capital/withdraw/history*' => Http::sequence()
            ->push([
                [
                    'id' => 'wd-1',
                    'amount' => '200.00',
                    'coin' => 'USDT',
                    'status' => 6,
                    'transferType' => 0,
                    'completeTime' => now()->subDays(10)->toDateTimeString(),
                ],
            ])
            ->whenEmpty(Http::response([])),
        'api.binance.com/sapi/v1/accountSnapshot*' => Http::response(['snapshotVos' => []]),
        'cdn.jsdelivr.net/*currencies/eur*' => Http::response([
            'eur' => [
                'btc' => 0.00002, // 1 EUR = 0.00002 BTC → 1 BTC = 50000 EUR
            ],
        ]),
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'BTC', 'free' => '0.5', 'locked' => '0.0'],
            ],
        ]),
        'api.binance.com/api/v3/ticker/price' => Http::response([
            ['symbol' => 'BTCEUR', 'price' => '50000.00'],
        ]),
    ]);

    $client = new BinanceClient('test-key', 'test-secret');
    $service = app(BinanceBalanceSyncService::class);
    $service->sync($account, $client, isFirstSync: true);

    $balance = $account->balances()->first();
    // Deposits: 0.5 BTC → converted via CurrencyConversionService (0.5 / 0.00002 = 25000 EUR)
    //           1000 USDT → treated as USD and converted (1000 USD via currency API)
    // Withdrawals: 200 USDT → treated as USD and converted (200 USD via currency API)
    // The exact amount depends on the currency conversion API response
    expect($balance->invested_amount)->not->toBeNull();
    expect($balance->invested_amount)->toBeGreaterThan(0);
});

test('excludes internal transfers from invested_amount calculation', function () {
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
        'api.binance.com/sapi/v1/capital/deposit/hisrec*' => Http::sequence()
            ->push([
                [
                    'id' => 'dep-1',
                    'amount' => '500.00',
                    'coin' => 'EUR',
                    'status' => 1,
                    'transferType' => 0,
                    'insertTime' => now()->subDays(30)->getTimestampMs(),
                ],
                [
                    'id' => 'dep-internal',
                    'amount' => '1000.00',
                    'coin' => 'EUR',
                    'status' => 1,
                    'transferType' => 1, // Internal transfer — should be excluded
                    'insertTime' => now()->subDays(20)->getTimestampMs(),
                ],
            ])
            ->whenEmpty(Http::response([])),
        'api.binance.com/sapi/v1/capital/withdraw/history*' => Http::sequence()
            ->push([])
            ->whenEmpty(Http::response([])),
        'api.binance.com/sapi/v1/accountSnapshot*' => Http::response(['snapshotVos' => []]),
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'EUR', 'free' => '500.00', 'locked' => '0.0'],
            ],
        ]),
        'api.binance.com/api/v3/ticker/price' => Http::response([]),
    ]);

    $client = new BinanceClient('test-key', 'test-secret');
    $service = app(BinanceBalanceSyncService::class);
    $service->sync($account, $client, isFirstSync: true);

    $balance = $account->balances()->first();
    // Only the external deposit of 500 EUR should count, internal 1000 EUR is excluded
    expect($balance->invested_amount)->toBe(50000); // 500 EUR → 50000 cents
});

test('filters deposits by status 1 and withdrawals by status 6', function () {
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
        'api.binance.com/sapi/v1/capital/deposit/hisrec*' => Http::sequence()
            ->push([
                [
                    'id' => 'dep-completed',
                    'amount' => '1000.00',
                    'coin' => 'EUR',
                    'status' => 1, // Completed
                    'transferType' => 0,
                    'insertTime' => now()->subDays(30)->getTimestampMs(),
                ],
                [
                    'id' => 'dep-pending',
                    'amount' => '2000.00',
                    'coin' => 'EUR',
                    'status' => 0, // Pending — should be excluded
                    'transferType' => 0,
                    'insertTime' => now()->subDays(20)->getTimestampMs(),
                ],
            ])
            ->whenEmpty(Http::response([])),
        'api.binance.com/sapi/v1/capital/withdraw/history*' => Http::sequence()
            ->push([
                [
                    'id' => 'wd-completed',
                    'amount' => '300.00',
                    'coin' => 'EUR',
                    'status' => 6, // Completed
                    'transferType' => 0,
                    'completeTime' => now()->subDays(10)->toDateTimeString(),
                ],
                [
                    'id' => 'wd-processing',
                    'amount' => '500.00',
                    'coin' => 'EUR',
                    'status' => 4, // Processing — should be excluded
                    'transferType' => 0,
                    'applyTime' => now()->subDays(5)->toDateTimeString(),
                ],
            ])
            ->whenEmpty(Http::response([])),
        'api.binance.com/sapi/v1/accountSnapshot*' => Http::response(['snapshotVos' => []]),
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'EUR', 'free' => '700.00', 'locked' => '0.0'],
            ],
        ]),
        'api.binance.com/api/v3/ticker/price' => Http::response([]),
    ]);

    $client = new BinanceClient('test-key', 'test-secret');
    $service = app(BinanceBalanceSyncService::class);
    $service->sync($account, $client, isFirstSync: true);

    $balance = $account->balances()->first();
    // Deposits: 1000 EUR (completed) — pending 2000 excluded
    // Withdrawals: 300 EUR (completed) — processing 500 excluded
    // Net: 1000 - 300 = 700 EUR → 70000 cents
    expect($balance->invested_amount)->toBe(70000);
});

test('returns null invested_amount when no deposit or withdrawal history', function () {
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
        'api.binance.com/sapi/v1/capital/deposit/hisrec*' => Http::response([]),
        'api.binance.com/sapi/v1/capital/withdraw/history*' => Http::response([]),
        'api.binance.com/sapi/v1/accountSnapshot*' => Http::response(['snapshotVos' => []]),
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'BTC', 'free' => '1.0', 'locked' => '0.0'],
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
    expect($balance->balance)->toBe(5000000);
    expect($balance->invested_amount)->toBeNull();
});

test('converts stablecoin deposits to fiat for invested_amount', function () {
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
        'api.binance.com/sapi/v1/capital/deposit/hisrec*' => Http::sequence()
            ->push([
                [
                    'id' => 'dep-1',
                    'amount' => '1000.00',
                    'coin' => 'USDT',
                    'status' => 1,
                    'transferType' => 0,
                    'insertTime' => now()->subDays(30)->getTimestampMs(),
                ],
                [
                    'id' => 'dep-2',
                    'amount' => '500.00',
                    'coin' => 'USDC',
                    'status' => 1,
                    'transferType' => 0,
                    'insertTime' => now()->subDays(20)->getTimestampMs(),
                ],
            ])
            ->whenEmpty(Http::response([])),
        'api.binance.com/sapi/v1/capital/withdraw/history*' => Http::sequence()
            ->push([])
            ->whenEmpty(Http::response([])),
        'api.binance.com/sapi/v1/accountSnapshot*' => Http::response(['snapshotVos' => []]),
        // CurrencyConversionService will convert USD to EUR
        'cdn.jsdelivr.net/*currencies/eur*' => Http::response([
            'eur' => [
                'usd' => 1.10, // 1 EUR = 1.10 USD → 1 USD = 0.909 EUR
            ],
        ]),
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'USDT', 'free' => '1500.00', 'locked' => '0.0'],
            ],
        ]),
        'api.binance.com/api/v3/ticker/price' => Http::response([
            ['symbol' => 'EURUSDT', 'price' => '1.10'],
        ]),
    ]);

    $client = new BinanceClient('test-key', 'test-secret');
    $service = app(BinanceBalanceSyncService::class);
    $service->sync($account, $client, isFirstSync: true);

    $balance = $account->balances()->first();
    // Stablecoins USDT and USDC are treated as USD
    // 1000 USD + 500 USD = 1500 USD total deposited
    // Converted to EUR: 1500 / 1.10 = 1363.636... EUR → 136364 cents
    expect($balance->invested_amount)->toBe(136364);
});

test('paginates within a window when deposit history hits the 1000-record limit', function () {
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

    // Build exactly 1000 deposits for the first page (triggers offset pagination)
    $firstPage = collect(range(1, 1000))->map(fn ($i) => [
        'id' => "dep-{$i}",
        'amount' => '1.00',
        'coin' => 'EUR',
        'status' => 1,
        'transferType' => 0,
        'insertTime' => now()->subDays(10)->getTimestampMs(),
    ])->all();

    // Second page has the remaining deposits (< 1000, so pagination stops)
    $secondPage = [
        [
            'id' => 'dep-1001',
            'amount' => '500.00',
            'coin' => 'EUR',
            'status' => 1,
            'transferType' => 0,
            'insertTime' => now()->subDays(5)->getTimestampMs(),
        ],
    ];

    Http::fake([
        'api.binance.com/sapi/v1/capital/deposit/hisrec*' => Http::sequence()
            ->push($firstPage)  // Window 1, offset 0: 1000 records
            ->push($secondPage) // Window 1, offset 1000: 1 record (stops pagination)
            ->whenEmpty(Http::response([])),
        'api.binance.com/sapi/v1/capital/withdraw/history*' => Http::response([]),
        'api.binance.com/sapi/v1/accountSnapshot*' => Http::response(['snapshotVos' => []]),
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'EUR', 'free' => '1500.00', 'locked' => '0.0'],
            ],
        ]),
        'api.binance.com/api/v3/ticker/price' => Http::response([]),
    ]);

    $client = new BinanceClient('test-key', 'test-secret');
    $service = app(BinanceBalanceSyncService::class);
    $service->sync($account, $client, isFirstSync: true);

    $balance = $account->balances()->first();
    // 1000 deposits of 1 EUR + 1 deposit of 500 EUR = 1500 EUR → 150000 cents
    expect($balance->invested_amount)->toBe(150000);
});

test('fetches deposits from older windows when recent window is empty', function () {
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
        // First window (most recent 90 days) returns empty, second window has a deposit
        'api.binance.com/sapi/v1/capital/deposit/hisrec*' => Http::sequence()
            ->push([]) // Window 1: no deposits in last 90 days
            ->push([
                [
                    'id' => 'dep-old',
                    'amount' => '1000.00',
                    'coin' => 'EUR',
                    'status' => 1,
                    'transferType' => 0,
                    'insertTime' => now()->subDays(120)->getTimestampMs(),
                ],
            ]) // Window 2: deposit from ~120 days ago
            ->whenEmpty(Http::response([])),
        'api.binance.com/sapi/v1/capital/withdraw/history*' => Http::response([]),
        'api.binance.com/sapi/v1/accountSnapshot*' => Http::response(['snapshotVos' => []]),
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'EUR', 'free' => '1000.00', 'locked' => '0.0'],
            ],
        ]),
        'api.binance.com/api/v3/ticker/price' => Http::response([]),
    ]);

    $client = new BinanceClient('test-key', 'test-secret');
    $service = app(BinanceBalanceSyncService::class);
    $service->sync($account, $client, isFirstSync: true);

    $balance = $account->balances()->first();
    // The deposit from the older window should be found: 1000 EUR → 100000 cents
    expect($balance->invested_amount)->toBe(100000);
});

test('subsequent sync reuses last invested_amount instead of recalculating', function () {
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

    // Pre-existing balance with invested_amount from a previous first sync
    $account->balances()->create([
        'balance_date' => now()->subDay()->toDateString(),
        'balance' => 5000000,
        'invested_amount' => 300000, // 3000 EUR
    ]);

    Http::fake([
        // No deposit/withdrawal API calls should be made on subsequent sync
        'api.binance.com/sapi/v1/accountSnapshot*' => Http::response(['snapshotVos' => []]),
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'BTC', 'free' => '1.0', 'locked' => '0.0'],
            ],
        ]),
        'api.binance.com/api/v3/ticker/price' => Http::response([
            ['symbol' => 'BTCEUR', 'price' => '50000.00'],
        ]),
    ]);

    $client = new BinanceClient('test-key', 'test-secret');
    $service = app(BinanceBalanceSyncService::class);
    $service->sync($account, $client, isFirstSync: false);

    $todayBalance = $account->balances()->where('balance_date', now()->toDateString())->first();
    expect($todayBalance->balance)->toBe(5000000);
    // Should carry forward the last invested_amount
    expect($todayBalance->invested_amount)->toBe(300000);
});
