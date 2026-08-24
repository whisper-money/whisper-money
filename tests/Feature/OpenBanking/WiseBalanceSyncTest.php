<?php

use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\Banking\WiseBalanceSyncService;
use App\Services\Banking\WiseClient;
use Illuminate\Support\Facades\Http;

test('syncs the wise wallet balance for the matching currency', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->wise()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => '36875276:EUR',
        'currency_code' => 'EUR',
    ]);

    Http::fake([
        'api.wise.com/v2/borderless-accounts*' => Http::response([
            [
                'id' => 44333087,
                'profileId' => 36875276,
                'balances' => [
                    ['currency' => 'USD', 'amount' => ['value' => 100.00, 'currency' => 'USD']],
                    ['currency' => 'EUR', 'amount' => ['value' => 19.81, 'currency' => 'EUR']],
                ],
            ],
        ]),
    ]);

    $service = app(WiseBalanceSyncService::class);
    $service->sync($account, new WiseClient('test-token'));

    expect($account->balances()->count())->toBe(1);

    $balance = $account->balances()->first();
    expect($balance->balance)->toBe(1981); // 19.81 EUR → 1981 cents
    expect($balance->balance_date->toDateString())->toBe(now()->toDateString());
});

test('updates the existing wise balance for today instead of duplicating', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->wise()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => '36875276:EUR',
        'currency_code' => 'EUR',
    ]);

    $account->balances()->create([
        'balance_date' => now()->toDateString(),
        'balance' => 36_688,
    ]);

    Http::fake([
        'api.wise.com/v2/borderless-accounts*' => Http::response([
            [
                'id' => 44333087,
                'profileId' => 36875276,
                'balances' => [
                    ['currency' => 'EUR', 'amount' => ['value' => 19.81, 'currency' => 'EUR']],
                ],
            ],
        ]),
    ]);

    $service = app(WiseBalanceSyncService::class);
    $service->sync($account, new WiseClient('test-token'));

    expect($account->balances()->count())->toBe(1);
    expect($account->balances()->first()->balance)->toBe(1981);
});

test('skips wise balance sync when external_account_id is missing', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->wise()->create([
        'user_id' => $user->id,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => null,
        'currency_code' => 'EUR',
    ]);

    $service = app(WiseBalanceSyncService::class);
    $service->sync($account, new WiseClient('test-token'));

    expect($account->balances()->count())->toBe(0);
    Http::assertNothingSent();
});
