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
        'api.bitpanda.com/v1/ticker' => Http::response([
            'BTC' => ['EUR' => '50000.00', 'USD' => '55000.00'],
        ]),
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
        'api.bitpanda.com/v1/fiatwallets/transactions*' => Http::response([
            'data' => [],
            'meta' => ['next_cursor' => null],
            'links' => [],
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
    ]);

    $client = new BitpandaClient('test-key');
    $service = app(BitpandaBalanceSyncService::class);
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(1);

    // 1 BTC * 50000 EUR + 500 EUR fiat = 50500 EUR → 5050000 cents
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
        'api.bitpanda.com/v1/ticker' => Http::response([
            'ETH' => ['EUR' => '2000.00', 'USD' => '2200.00'],
        ]),
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
        'api.bitpanda.com/v1/fiatwallets/transactions*' => Http::response([
            'data' => [],
            'meta' => ['next_cursor' => null],
            'links' => [],
        ]),
        'api.bitpanda.com/v1/fiatwallets' => Http::response([
            'data' => [],
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

test('syncs bitpanda balance by converting fiat wallets to newly allowed primary currency', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'ARS']);
    $connection = BankingConnection::factory()->bitpanda()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'bitpanda-portfolio',
        'currency_code' => 'ARS',
    ]);

    Http::fake([
        'api.bitpanda.com/v1/ticker' => Http::response([
            'BTC' => ['ARS' => '70000000.00'],
        ]),
        'api.bitpanda.com/v1/wallets' => Http::response([
            'data' => [
                [
                    'type' => 'wallet',
                    'attributes' => [
                        'cryptocoin_id' => '1',
                        'cryptocoin_symbol' => 'BTC',
                        'balance' => '0.01000000',
                        'is_default' => true,
                        'name' => 'BTC wallet',
                        'deleted' => false,
                    ],
                    'id' => 'wallet-uuid-1',
                ],
            ],
        ]),
        'api.bitpanda.com/v1/fiatwallets/transactions*' => Http::response([
            'data' => [],
            'meta' => ['next_cursor' => null],
            'links' => [],
        ]),
        'api.bitpanda.com/v1/fiatwallets' => Http::response([
            'data' => [
                [
                    'type' => 'fiat_wallet',
                    'attributes' => [
                        'fiat_id' => '1',
                        'fiat_symbol' => 'USD',
                        'balance' => '500.00000000',
                        'name' => 'USD Wallet',
                    ],
                    'id' => 'fiat-wallet-uuid-1',
                ],
            ],
        ]),
        'cdn.jsdelivr.net/*currencies/ars*' => Http::response([
            'ars' => [
                'usd' => 0.0007142857,
            ],
        ]),
    ]);

    $client = new BitpandaClient('test-key');
    $service = app(BitpandaBalanceSyncService::class);
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(1);

    $balance = $account->balances()->first();
    expect($balance->balance)->toBe((int) round((700000 + (500 / 0.0007142857)) * 100));
});

test('syncs bitpanda balance including bitpanda-specific indices', function () {
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
        'api.bitpanda.com/v1/ticker' => Http::response([
            'BTC' => ['EUR' => '56911.68'],
            'BCI10' => ['EUR' => '10.40'],
            'BCI5' => ['EUR' => '16.00'],
        ]),
        'api.bitpanda.com/v1/wallets' => Http::response([
            'data' => [
                [
                    'type' => 'wallet',
                    'attributes' => [
                        'cryptocoin_id' => '1',
                        'cryptocoin_symbol' => 'BTC',
                        'balance' => '0.01000000',
                        'is_default' => true,
                        'name' => 'BTC wallet',
                        'deleted' => false,
                    ],
                    'id' => 'wallet-uuid-1',
                ],
                [
                    'type' => 'wallet',
                    'attributes' => [
                        'cryptocoin_id' => '100',
                        'cryptocoin_symbol' => 'BCI10',
                        'balance' => '0.20000000',
                        'is_default' => true,
                        'name' => 'BCI10 wallet',
                        'deleted' => false,
                    ],
                    'id' => 'wallet-uuid-2',
                ],
                [
                    'type' => 'wallet',
                    'attributes' => [
                        'cryptocoin_id' => '101',
                        'cryptocoin_symbol' => 'BCI5',
                        'balance' => '0.02000000',
                        'is_default' => true,
                        'name' => 'BCI5 wallet',
                        'deleted' => false,
                    ],
                    'id' => 'wallet-uuid-3',
                ],
            ],
        ]),
        'api.bitpanda.com/v1/fiatwallets/transactions*' => Http::response([
            'data' => [],
            'meta' => ['next_cursor' => null],
            'links' => [],
        ]),
        'api.bitpanda.com/v1/fiatwallets' => Http::response([
            'data' => [],
        ]),
    ]);

    $client = new BitpandaClient('test-key');
    $service = app(BitpandaBalanceSyncService::class);
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(1);

    // 0.01 BTC * 56911.68 = 569.1168
    // 0.20 BCI10 * 10.40 = 2.08
    // 0.02 BCI5 * 16.00 = 0.32
    // Total = 571.5168 EUR → 57152 cents
    $balance = $account->balances()->first();
    expect($balance->balance)->toBe(57152);
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
        'api.bitpanda.com/v1/ticker' => Http::response([
            'BTC' => ['EUR' => '50000.00'],
        ]),
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
        'api.bitpanda.com/v1/fiatwallets/transactions*' => Http::response([
            'data' => [],
            'meta' => ['next_cursor' => null],
            'links' => [],
        ]),
        'api.bitpanda.com/v1/fiatwallets' => Http::response([
            'data' => [],
        ]),
    ]);

    $client = new BitpandaClient('test-key');
    $service = app(BitpandaBalanceSyncService::class);
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(1);
    // 1 BTC * 50000 EUR = 5000000 cents
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
        'api.bitpanda.com/v1/ticker' => Http::response([]),
        'api.bitpanda.com/v1/wallets' => Http::response([
            'data' => [],
        ]),
        'api.bitpanda.com/v1/fiatwallets/transactions*' => Http::response([
            'data' => [],
            'meta' => ['next_cursor' => null],
            'links' => [],
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
        'api.bitpanda.com/v1/ticker' => Http::response([
            'BTC' => ['EUR' => '50000.00'],
        ]),
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
        'api.bitpanda.com/v1/fiatwallets/transactions*' => Http::response([
            'data' => [],
            'meta' => ['next_cursor' => null],
            'links' => [],
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
    $client->shouldNotReceive('getTickerPrices');
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
        'api.bitpanda.com/v1/ticker' => Http::response([]),
        'api.bitpanda.com/v1/wallets' => Http::response([
            'data' => [],
        ]),
        'api.bitpanda.com/v1/fiatwallets/transactions*' => Http::response([
            'data' => [],
            'meta' => ['next_cursor' => null],
            'links' => [],
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

test('uses correct currency from ticker when account is USD', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'USD']);
    $connection = BankingConnection::factory()->bitpanda()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'bitpanda-portfolio',
        'currency_code' => 'USD',
    ]);

    Http::fake([
        'api.bitpanda.com/v1/ticker' => Http::response([
            'BTC' => ['EUR' => '50000.00', 'USD' => '55000.00'],
        ]),
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
        'api.bitpanda.com/v1/fiatwallets/transactions*' => Http::response([
            'data' => [],
            'meta' => ['next_cursor' => null],
            'links' => [],
        ]),
        'api.bitpanda.com/v1/fiatwallets' => Http::response([
            'data' => [],
        ]),
    ]);

    $client = new BitpandaClient('test-key');
    $service = app(BitpandaBalanceSyncService::class);
    $service->sync($account, $client);

    expect($account->balances()->count())->toBe(1);
    // Should use USD price: 1 BTC * 55000 USD = 5500000 cents
    expect($account->balances()->first()->balance)->toBe(5500000);
});

test('calculates invested_amount from fiat deposit and withdrawal history', function () {
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
        'api.bitpanda.com/v1/ticker' => Http::response([
            'BTC' => ['EUR' => '50000.00'],
        ]),
        'api.bitpanda.com/v1/wallets' => Http::response([
            'data' => [
                [
                    'type' => 'wallet',
                    'attributes' => [
                        'cryptocoin_id' => '1',
                        'cryptocoin_symbol' => 'BTC',
                        'balance' => '0.10000000',
                        'is_default' => true,
                        'name' => 'BTC wallet',
                        'deleted' => false,
                    ],
                    'id' => 'wallet-uuid-1',
                ],
            ],
        ]),
        'api.bitpanda.com/v1/fiatwallets/transactions*' => Http::sequence()
            ->push([
                'data' => [
                    [
                        'type' => 'fiat_wallet_transaction',
                        'id' => 'tx-1',
                        'attributes' => [
                            'amount' => '1000.00',
                            'status' => 'finished',
                            'fiat_id' => 'EUR',
                        ],
                    ],
                    [
                        'type' => 'fiat_wallet_transaction',
                        'id' => 'tx-2',
                        'attributes' => [
                            'amount' => '500.00',
                            'status' => 'finished',
                            'fiat_id' => 'EUR',
                        ],
                    ],
                ],
                'meta' => ['next_cursor' => null],
                'links' => [],
            ])
            ->push([
                'data' => [
                    [
                        'type' => 'fiat_wallet_transaction',
                        'id' => 'tx-3',
                        'attributes' => [
                            'amount' => '200.00',
                            'status' => 'finished',
                            'fiat_id' => 'EUR',
                        ],
                    ],
                ],
                'meta' => ['next_cursor' => null],
                'links' => [],
            ]),
        'api.bitpanda.com/v1/fiatwallets' => Http::response([
            'data' => [],
        ]),
    ]);

    $client = new BitpandaClient('test-key');
    $service = app(BitpandaBalanceSyncService::class);
    $service->sync($account, $client);

    $balance = $account->balances()->first();
    // Balance: 0.1 BTC * 50000 = 5000 EUR → 500000 cents
    expect($balance->balance)->toBe(500000);
    // Invested: deposits(1000 + 500) - withdrawals(200) = 1300 EUR → 130000 cents
    expect($balance->invested_amount)->toBe(130000);
});

test('only counts finished fiat transactions for invested_amount', function () {
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
        'api.bitpanda.com/v1/ticker' => Http::response([]),
        'api.bitpanda.com/v1/wallets' => Http::response(['data' => []]),
        'api.bitpanda.com/v1/fiatwallets/transactions*' => Http::sequence()
            ->push([
                'data' => [
                    [
                        'type' => 'fiat_wallet_transaction',
                        'id' => 'tx-1',
                        'attributes' => [
                            'amount' => '1000.00',
                            'status' => 'finished',
                            'fiat_id' => 'EUR',
                        ],
                    ],
                    [
                        'type' => 'fiat_wallet_transaction',
                        'id' => 'tx-2',
                        'attributes' => [
                            'amount' => '500.00',
                            'status' => 'pending',
                            'fiat_id' => 'EUR',
                        ],
                    ],
                ],
                'meta' => ['next_cursor' => null],
                'links' => [],
            ])
            ->push([
                'data' => [],
                'meta' => ['next_cursor' => null],
                'links' => [],
            ]),
        'api.bitpanda.com/v1/fiatwallets' => Http::response(['data' => []]),
    ]);

    $client = new BitpandaClient('test-key');
    $service = app(BitpandaBalanceSyncService::class);
    $service->sync($account, $client);

    $balance = $account->balances()->first();
    // Only the finished deposit of 1000 should count, pending 500 is ignored
    expect($balance->invested_amount)->toBe(100000); // 1000 EUR → 100000 cents
});

test('converts bitpanda fiat transactions that do not match the target currency for invested_amount', function () {
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
        'api.bitpanda.com/v1/ticker' => Http::response([]),
        'api.bitpanda.com/v1/wallets' => Http::response(['data' => []]),
        'api.bitpanda.com/v1/fiatwallets/transactions*' => Http::sequence()
            ->push([
                'data' => [
                    [
                        'type' => 'fiat_wallet_transaction',
                        'id' => 'tx-1',
                        'attributes' => [
                            'amount' => '1000.00',
                            'status' => 'finished',
                            'fiat_id' => 'EUR',
                        ],
                    ],
                    [
                        'type' => 'fiat_wallet_transaction',
                        'id' => 'tx-2',
                        'attributes' => [
                            'amount' => '500.00',
                            'status' => 'finished',
                            'fiat_id' => 'USD',
                        ],
                    ],
                    [
                        'type' => 'fiat_wallet_transaction',
                        'id' => 'tx-3',
                        'attributes' => [
                            'amount' => '250.00',
                            'status' => 'finished',
                            'fiat_id' => 'EUR',
                        ],
                    ],
                ],
                'meta' => ['next_cursor' => null],
                'links' => [],
            ])
            ->push([
                'data' => [],
                'meta' => ['next_cursor' => null],
                'links' => [],
            ]),
        'api.bitpanda.com/v1/fiatwallets' => Http::response(['data' => []]),
        'cdn.jsdelivr.net/*currencies/eur*' => Http::response([
            'eur' => [
                'usd' => 1.10,
            ],
        ]),
    ]);

    $client = new BitpandaClient('test-key');
    $service = app(BitpandaBalanceSyncService::class);
    $service->sync($account, $client);

    $balance = $account->balances()->first();
    // 1000 EUR + 250 EUR + (500 USD / 1.10) = 1704.55 EUR → 170455 cents
    expect($balance->invested_amount)->toBe(170455);
});

test('converts bitpanda fiat transactions to newly allowed primary currency for invested amount', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'ARS']);
    $connection = BankingConnection::factory()->bitpanda()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'bitpanda-portfolio',
        'currency_code' => 'ARS',
    ]);

    Http::fake([
        'api.bitpanda.com/v1/ticker' => Http::response([]),
        'api.bitpanda.com/v1/wallets' => Http::response(['data' => []]),
        'api.bitpanda.com/v1/fiatwallets/transactions*' => Http::sequence()
            ->push([
                'data' => [
                    [
                        'type' => 'fiat_wallet_transaction',
                        'id' => 'tx-1',
                        'attributes' => [
                            'amount' => '1000.00',
                            'status' => 'finished',
                            'fiat_id' => 'USD',
                        ],
                    ],
                    [
                        'type' => 'fiat_wallet_transaction',
                        'id' => 'tx-2',
                        'attributes' => [
                            'amount' => '250.00',
                            'status' => 'finished',
                            'fiat_id' => 'ARS',
                        ],
                    ],
                ],
                'meta' => ['next_cursor' => null],
                'links' => [],
            ])
            ->push([
                'data' => [],
                'meta' => ['next_cursor' => null],
                'links' => [],
            ]),
        'api.bitpanda.com/v1/fiatwallets' => Http::response(['data' => []]),
        'cdn.jsdelivr.net/*currencies/ars*' => Http::response([
            'ars' => [
                'usd' => 0.0007142857,
            ],
        ]),
    ]);

    $client = new BitpandaClient('test-key');
    $service = app(BitpandaBalanceSyncService::class);
    $service->sync($account, $client);

    $balance = $account->balances()->first();
    expect($balance->invested_amount)->toBe((int) round(((1000 / 0.0007142857) + 250) * 100));
});

test('returns null invested_amount when no fiat transactions exist', function () {
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
        'api.bitpanda.com/v1/ticker' => Http::response([]),
        'api.bitpanda.com/v1/wallets' => Http::response(['data' => []]),
        'api.bitpanda.com/v1/fiatwallets/transactions*' => Http::response([
            'data' => [],
            'meta' => ['next_cursor' => null],
            'links' => [],
        ]),
        'api.bitpanda.com/v1/fiatwallets' => Http::response(['data' => []]),
    ]);

    $client = new BitpandaClient('test-key');
    $service = app(BitpandaBalanceSyncService::class);
    $service->sync($account, $client);

    $balance = $account->balances()->first();
    expect($balance->invested_amount)->toBeNull();
});
