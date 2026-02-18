<?php

use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\Banking\BitpandaBalanceSyncService;
use App\Services\Banking\BitpandaClient;
use Illuminate\Support\Facades\Http;

test('syncs bitpanda balance with crypto and fiat wallets', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->bitpanda()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'bitpanda-portfolio',
        'currency_code' => 'EUR',
    ]);

    Http::fake([
        'api.bitpanda.com/v1/wallets' => Http::response([
            'data' => [
                [
                    'type' => 'wallet',
                    'attributes' => [
                        'cryptocoin_id' => '1',
                        'cryptocoin_symbol' => 'BTC',
                        'balance' => '1.00000000',
                        'is_default' => true,
                        'name' => 'BTC wallet',
                        'deleted' => false,
                    ],
                    'id' => 'wallet-uuid-1',
                ],
            ],
        ]),
        'api.bitpanda.com/v1/fiatwallets' => Http::response([
            'data' => [
                [
                    'type' => 'fiat_wallet',
                    'attributes' => [
                        'fiat_id' => '1',
                        'fiat_symbol' => 'EUR',
                        'balance' => '500.00000000',
                        'name' => 'EUR Wallet',
                    ],
                    'id' => 'fiat-wallet-uuid-1',
                ],
            ],
        ]),
        'cdn.jsdelivr.net/*currencies/eur*' => Http::response([
            'eur' => [
                'btc' => 0.00002, // 1 EUR = 0.00002 BTC → 1 BTC = 50000 EUR
            ],
        ]),
    ]);

    $client = new BitpandaClient('test-key');
    $service = app(BitpandaBalanceSyncService::class);
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(1);

    // 1 BTC = 50000 EUR + 500 EUR fiat = 50500 EUR → 5050000 cents
    $balance = $account->balances()->first();
    expect($balance->balance)->toBe(5050000);
    expect($balance->balance_date->toDateString())->toBe(now()->toDateString());
});

test('syncs bitpanda balance with crypto only', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->bitpanda()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'bitpanda-portfolio',
        'currency_code' => 'EUR',
    ]);

    Http::fake([
        'api.bitpanda.com/v1/wallets' => Http::response([
            'data' => [
                [
                    'type' => 'wallet',
                    'attributes' => [
                        'cryptocoin_id' => '27',
                        'cryptocoin_symbol' => 'ETH',
                        'balance' => '5.00000000',
                        'is_default' => true,
                        'name' => 'ETH wallet',
                        'deleted' => false,
                    ],
                    'id' => 'wallet-uuid-2',
                ],
            ],
        ]),
        'api.bitpanda.com/v1/fiatwallets' => Http::response([
            'data' => [],
        ]),
        'cdn.jsdelivr.net/*currencies/eur*' => Http::response([
            'eur' => [
                'eth' => 0.0005, // 1 EUR = 0.0005 ETH → 1 ETH = 2000 EUR
            ],
        ]),
    ]);

    $client = new BitpandaClient('test-key');
    $service = app(BitpandaBalanceSyncService::class);
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(1);

    // 5 ETH * 2000 EUR = 10000 EUR → 1000000 cents
    $balance = $account->balances()->first();
    expect($balance->balance)->toBe(1000000);
});

test('syncs bitpanda balance with fiat wallets in different currencies', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->bitpanda()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'bitpanda-portfolio',
        'currency_code' => 'EUR',
    ]);

    Http::fake([
        'api.bitpanda.com/v1/wallets' => Http::response([
            'data' => [],
        ]),
        'api.bitpanda.com/v1/fiatwallets' => Http::response([
            'data' => [
                [
                    'type' => 'fiat_wallet',
                    'attributes' => [
                        'fiat_id' => '1',
                        'fiat_symbol' => 'EUR',
                        'balance' => '1000.00000000',
                        'name' => 'EUR Wallet',
                    ],
                    'id' => 'fiat-wallet-uuid-1',
                ],
                [
                    'type' => 'fiat_wallet',
                    'attributes' => [
                        'fiat_id' => '2',
                        'fiat_symbol' => 'USD',
                        'balance' => '500.00000000',
                        'name' => 'USD Wallet',
                    ],
                    'id' => 'fiat-wallet-uuid-2',
                ],
            ],
        ]),
        'cdn.jsdelivr.net/*currencies/eur*' => Http::response([
            'eur' => [
                'usd' => 1.10, // 1 EUR = 1.10 USD → 500 USD = 454.55 EUR
            ],
        ]),
    ]);

    $client = new BitpandaClient('test-key');
    $service = app(BitpandaBalanceSyncService::class);
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(1);

    // 1000 EUR + (500 USD / 1.10) = 1000 + 454.55 = 1454.55 EUR → 145455 cents
    $balance = $account->balances()->first();
    expect($balance->balance)->toBe(145455);
});

test('updates existing balance for same date', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->bitpanda()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'bitpanda-portfolio',
        'currency_code' => 'EUR',
    ]);

    $account->balances()->create([
        'balance_date' => now()->toDateString(),
        'balance' => 100000,
    ]);

    Http::fake([
        'api.bitpanda.com/v1/wallets' => Http::response([
            'data' => [
                [
                    'type' => 'wallet',
                    'attributes' => [
                        'cryptocoin_id' => '1',
                        'cryptocoin_symbol' => 'BTC',
                        'balance' => '1.00000000',
                        'is_default' => true,
                        'name' => 'BTC wallet',
                        'deleted' => false,
                    ],
                    'id' => 'wallet-uuid-1',
                ],
            ],
        ]),
        'api.bitpanda.com/v1/fiatwallets' => Http::response([
            'data' => [],
        ]),
        'cdn.jsdelivr.net/*currencies/eur*' => Http::response([
            'eur' => [
                'btc' => 0.00002, // 1 BTC = 50000 EUR
            ],
        ]),
    ]);

    $client = new BitpandaClient('test-key');
    $service = app(BitpandaBalanceSyncService::class);
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(1);
    // 1 BTC = 50000 EUR → 5000000 cents
    expect($account->balances()->first()->balance)->toBe(5000000);
});

test('handles empty wallets gracefully', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->bitpanda()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'bitpanda-portfolio',
        'currency_code' => 'EUR',
    ]);

    Http::fake([
        'api.bitpanda.com/v1/wallets' => Http::response([
            'data' => [],
        ]),
        'api.bitpanda.com/v1/fiatwallets' => Http::response([
            'data' => [],
        ]),
    ]);

    $client = new BitpandaClient('test-key');
    $service = app(BitpandaBalanceSyncService::class);
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(1);
    expect($account->balances()->first()->balance)->toBe(0);
});

test('skips deleted crypto wallets', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->bitpanda()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'bitpanda-portfolio',
        'currency_code' => 'EUR',
    ]);

    Http::fake([
        'api.bitpanda.com/v1/wallets' => Http::response([
            'data' => [
                [
                    'type' => 'wallet',
                    'attributes' => [
                        'cryptocoin_id' => '1',
                        'cryptocoin_symbol' => 'BTC',
                        'balance' => '1.00000000',
                        'is_default' => true,
                        'name' => 'BTC wallet',
                        'deleted' => true,
                    ],
                    'id' => 'wallet-uuid-1',
                ],
            ],
        ]),
        'api.bitpanda.com/v1/fiatwallets' => Http::response([
            'data' => [],
        ]),
    ]);

    $client = new BitpandaClient('test-key');
    $service = app(BitpandaBalanceSyncService::class);
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(1);
    expect($account->balances()->first()->balance)->toBe(0);
});

test('skips account without external_account_id', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'external_account_id' => null,
    ]);

    $client = Mockery::mock(BitpandaClient::class);
    $client->shouldNotReceive('getCryptoWallets');

    $service = app(BitpandaBalanceSyncService::class);
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(0);
});

test('skips zero balance fiat wallets', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->bitpanda()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'bitpanda-portfolio',
        'currency_code' => 'EUR',
    ]);

    Http::fake([
        'api.bitpanda.com/v1/wallets' => Http::response([
            'data' => [],
        ]),
        'api.bitpanda.com/v1/fiatwallets' => Http::response([
            'data' => [
                [
                    'type' => 'fiat_wallet',
                    'attributes' => [
                        'fiat_id' => '1',
                        'fiat_symbol' => 'EUR',
                        'balance' => '0.00000000',
                        'name' => 'EUR Wallet',
                    ],
                    'id' => 'fiat-wallet-uuid-1',
                ],
                [
                    'type' => 'fiat_wallet',
                    'attributes' => [
                        'fiat_id' => '1',
                        'fiat_symbol' => 'EUR',
                        'balance' => '250.00000000',
                        'name' => 'EUR Wallet 2',
                    ],
                    'id' => 'fiat-wallet-uuid-2',
                ],
            ],
        ]),
    ]);

    $client = new BitpandaClient('test-key');
    $service = app(BitpandaBalanceSyncService::class);
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(1);
    // Only the 250 EUR wallet counts (zero balance one is skipped)
    expect($account->balances()->first()->balance)->toBe(25000);
});
