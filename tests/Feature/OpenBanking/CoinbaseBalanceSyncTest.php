<?php

use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\Banking\CoinbaseBalanceSyncService;
use App\Services\Banking\CoinbaseClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

afterEach(fn () => Carbon::setTestNow());

function ecPrivateKeyForCoinbase(): string
{
    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);

    openssl_pkey_export($key, $pem);

    return $pem;
}

test('syncs coinbase balance with crypto and fiat wallets', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->coinbase()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'coinbase-portfolio',
        'currency_code' => 'EUR',
    ]);

    Http::fake([
        'api.coinbase.com/api/v3/brokerage/accounts*' => Http::response([
            'accounts' => [
                [
                    'uuid' => 'cb-1',
                    'name' => 'BTC',
                    'currency' => 'BTC',
                    'available_balance' => ['value' => '1.0', 'currency' => 'BTC'],
                    'hold' => ['value' => '0', 'currency' => 'BTC'],
                    'active' => true,
                    'type' => 'ACCOUNT_TYPE_CRYPTO',
                ],
                [
                    'uuid' => 'cb-2',
                    'name' => 'EUR',
                    'currency' => 'EUR',
                    'available_balance' => ['value' => '500.00', 'currency' => 'EUR'],
                    'hold' => ['value' => '0', 'currency' => 'EUR'],
                    'active' => true,
                    'type' => 'ACCOUNT_TYPE_FIAT',
                ],
            ],
            'has_next' => false,
            'cursor' => '',
            'size' => 2,
        ]),
        'api.coinbase.com/api/v3/brokerage/best_bid_ask*' => Http::response([
            'pricebooks' => [
                [
                    'product_id' => 'BTC-EUR',
                    'bids' => [['price' => '49900.00', 'size' => '1']],
                    'asks' => [['price' => '50100.00', 'size' => '1']],
                ],
            ],
        ]),
    ]);

    $client = new CoinbaseClient('organizations/org/apiKeys/key', ecPrivateKeyForCoinbase());
    $service = app(CoinbaseBalanceSyncService::class);
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(1);

    // 1 BTC * 50000 (mid of 49900/50100) EUR + 500 EUR fiat = 50500 EUR → 5_050_000 cents
    $balance = $account->balances()->first();
    expect($balance->balance)->toBe(5_050_000);
    expect($balance->balance_date->toDateString())->toBe(now()->toDateString());
});

test('first sync creates twelve monthly coinbase historical balances', function () {
    Carbon::setTestNow('2026-05-14 12:00:00');

    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->coinbase()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'coinbase-portfolio',
        'currency_code' => 'EUR',
    ]);

    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_contains($url, '/api/v3/brokerage/accounts')) {
            return Http::response([
                'accounts' => [
                    [
                        'uuid' => 'cb-1',
                        'name' => 'BTC',
                        'currency' => 'BTC',
                        'available_balance' => ['value' => '1.0', 'currency' => 'BTC'],
                        'hold' => ['value' => '0', 'currency' => 'BTC'],
                        'active' => true,
                        'type' => 'ACCOUNT_TYPE_CRYPTO',
                    ],
                    [
                        'uuid' => 'cb-2',
                        'name' => 'EUR',
                        'currency' => 'EUR',
                        'available_balance' => ['value' => '50.00', 'currency' => 'EUR'],
                        'hold' => ['value' => '0', 'currency' => 'EUR'],
                        'active' => true,
                        'type' => 'ACCOUNT_TYPE_FIAT',
                    ],
                ],
                'has_next' => false,
                'cursor' => '',
                'size' => 2,
            ]);
        }

        if (str_contains($url, '/api/v3/brokerage/products/BTC-EUR/candles')) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);

            $start = Carbon::createFromTimestamp((int) $query['start'])->startOfDay();
            $end = Carbon::createFromTimestamp((int) $query['end'])->startOfDay();
            $candles = [];

            for ($date = $start->copy(); $date->lessThanOrEqualTo($end); $date->addDay()) {
                $candles[] = [
                    'start' => (string) $date->getTimestamp(),
                    'low' => '100.00',
                    'high' => '100.00',
                    'open' => '100.00',
                    'close' => '100.00',
                    'volume' => '1.00',
                ];
            }

            return Http::response(['candles' => $candles]);
        }

        if (str_contains($url, '/api/v3/brokerage/best_bid_ask')) {
            return Http::response([
                'pricebooks' => [
                    [
                        'product_id' => 'BTC-EUR',
                        'bids' => [['price' => '200.00', 'size' => '1']],
                        'asks' => [['price' => '200.00', 'size' => '1']],
                    ],
                ],
            ]);
        }

        return Http::response([], 404);
    });

    $client = new CoinbaseClient('organizations/org/apiKeys/key', ecPrivateKeyForCoinbase());
    $service = app(CoinbaseBalanceSyncService::class);
    $service->sync($account, $client, isFirstSync: true);

    $balances = $account->balances()->orderBy('balance_date')->get();

    expect($balances)->toHaveCount(13);
    expect($balances->first()->balance_date->toDateString())->toBe('2025-05-14');
    expect($balances->first()->balance)->toBe(15_000);
    expect($balances->get(11)->balance_date->toDateString())->toBe('2026-04-14');
    expect($balances->get(11)->balance)->toBe(15_000);
    expect($balances->last()->balance_date->toDateString())->toBe('2026-05-14');
    expect($balances->last()->balance)->toBe(25_000);
});

test('historical sync falls back to usd coinbase candles when target pair is unavailable', function () {
    Carbon::setTestNow('2026-05-14 12:00:00');

    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->coinbase()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'coinbase-portfolio',
        'currency_code' => 'EUR',
    ]);

    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_contains($url, '/api/v3/brokerage/accounts')) {
            return Http::response([
                'accounts' => [
                    [
                        'uuid' => 'cb-1',
                        'name' => 'SOL',
                        'currency' => 'SOL',
                        'available_balance' => ['value' => '2.0', 'currency' => 'SOL'],
                        'hold' => ['value' => '0', 'currency' => 'SOL'],
                        'active' => true,
                        'type' => 'ACCOUNT_TYPE_CRYPTO',
                    ],
                ],
                'has_next' => false,
                'cursor' => '',
                'size' => 1,
            ]);
        }

        if (str_contains($url, '/api/v3/brokerage/products/SOL-EUR/candles')) {
            return Http::response(['error' => 'not found'], 404);
        }

        if (str_contains($url, '/api/v3/brokerage/products/SOL-USD/candles')) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);

            $start = Carbon::createFromTimestamp((int) $query['start'])->startOfDay();
            $end = Carbon::createFromTimestamp((int) $query['end'])->startOfDay();
            $candles = [];

            for ($date = $start->copy(); $date->lessThanOrEqualTo($end); $date->addDay()) {
                $candles[] = [
                    'start' => (string) $date->getTimestamp(),
                    'low' => '100.00',
                    'high' => '100.00',
                    'open' => '100.00',
                    'close' => '100.00',
                    'volume' => '1.00',
                ];
            }

            return Http::response(['candles' => $candles]);
        }

        if (str_contains($url, 'cdn.jsdelivr.net') || str_contains($url, 'currency-api.pages.dev')) {
            return Http::response(['eur' => ['usd' => 2.0]]);
        }

        if (str_contains($url, '/api/v3/brokerage/best_bid_ask')) {
            return Http::response([
                'pricebooks' => [
                    [
                        'product_id' => 'SOL-EUR',
                        'bids' => [['price' => '50.00', 'size' => '1']],
                        'asks' => [['price' => '50.00', 'size' => '1']],
                    ],
                ],
            ]);
        }

        return Http::response([], 404);
    });

    $client = new CoinbaseClient('organizations/org/apiKeys/key', ecPrivateKeyForCoinbase());
    $service = app(CoinbaseBalanceSyncService::class);
    $service->sync($account, $client, isFirstSync: true);

    $balances = $account->balances()->orderBy('balance_date')->get();

    expect($balances)->toHaveCount(13);
    expect($balances->first()->balance)->toBe(10_000);
    expect($balances->last()->balance)->toBe(10_000);
});

test('subsequent sync backfills coinbase monthly history when missing', function () {
    Carbon::setTestNow('2026-05-14 12:00:00');

    $user = User::factory()->onboarded()->create(['currency_code' => 'USD']);
    $connection = BankingConnection::factory()->coinbase()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subHour(),
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'coinbase-portfolio',
        'currency_code' => 'USD',
    ]);

    $account->balances()->create([
        'balance_date' => now()->toDateString(),
        'balance' => 100_000,
    ]);

    Http::fake([
        'api.coinbase.com/api/v3/brokerage/accounts*' => Http::response([
            'accounts' => [
                [
                    'uuid' => 'cb-1',
                    'name' => 'USDC',
                    'currency' => 'USDC',
                    'available_balance' => ['value' => '1000.00', 'currency' => 'USDC'],
                    'hold' => ['value' => '0', 'currency' => 'USDC'],
                    'active' => true,
                    'type' => 'ACCOUNT_TYPE_CRYPTO',
                ],
            ],
            'has_next' => false,
            'cursor' => '',
            'size' => 1,
        ]),
        'api.coinbase.com/api/v3/brokerage/best_bid_ask*' => Http::response([
            'pricebooks' => [],
        ]),
    ]);

    $client = new CoinbaseClient('organizations/org/apiKeys/key', ecPrivateKeyForCoinbase());
    $service = app(CoinbaseBalanceSyncService::class);
    $service->sync($account, $client, isFirstSync: false, backfillMissingHistory: true);

    $balances = $account->balances()->orderBy('balance_date')->get();

    expect($balances)->toHaveCount(13);
    expect($balances->first()->balance_date->toDateString())->toBe('2025-05-14');
    expect($balances->first()->balance)->toBe(100_000);
    expect($balances->get(11)->balance_date->toDateString())->toBe('2026-04-14');
    expect($balances->last()->balance_date->toDateString())->toBe('2026-05-14');
});

test('treats usd stablecoins as usd when valuing', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'USD']);
    $connection = BankingConnection::factory()->coinbase()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'coinbase-portfolio',
        'currency_code' => 'USD',
    ]);

    Http::fake([
        'api.coinbase.com/api/v3/brokerage/accounts*' => Http::response([
            'accounts' => [
                [
                    'uuid' => 'cb-1',
                    'name' => 'USDC',
                    'currency' => 'USDC',
                    'available_balance' => ['value' => '1000.00', 'currency' => 'USDC'],
                    'hold' => ['value' => '0', 'currency' => 'USDC'],
                    'active' => true,
                    'type' => 'ACCOUNT_TYPE_CRYPTO',
                ],
            ],
            'has_next' => false,
            'cursor' => '',
            'size' => 1,
        ]),
        'api.coinbase.com/api/v3/brokerage/best_bid_ask*' => Http::response([
            'pricebooks' => [],
        ]),
    ]);

    $client = new CoinbaseClient('organizations/org/apiKeys/key', ecPrivateKeyForCoinbase());
    $service = app(CoinbaseBalanceSyncService::class);
    $service->sync($account, $client);

    $balance = $account->balances()->first();
    expect($balance->balance)->toBe(100_000); // 1000 USD → 100000 cents
});

test('skips sync when external_account_id is missing', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->coinbase()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => null,
        'currency_code' => 'EUR',
    ]);

    $client = new CoinbaseClient('organizations/org/apiKeys/key', ecPrivateKeyForCoinbase());
    $service = app(CoinbaseBalanceSyncService::class);
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(0);
    Http::assertNothingSent();
});
