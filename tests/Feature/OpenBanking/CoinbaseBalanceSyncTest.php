<?php

use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\Banking\CoinbaseBalanceSyncService;
use App\Services\Banking\CoinbaseClient;
use Illuminate\Support\Facades\Http;

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
