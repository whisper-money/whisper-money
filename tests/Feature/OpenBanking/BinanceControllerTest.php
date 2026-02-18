<?php

use App\Enums\AccountType;
use App\Enums\BankingConnectionStatus;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

test('users can connect a binance account with valid credentials', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    Feature::for($user)->activate('open-banking');

    Http::fake([
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'BTC', 'free' => '0.5', 'locked' => '0.0'],
            ],
        ]),
    ]);

    $response = $this->actingAs($user)->postJson('/open-banking/binance/connect', [
        'api_key' => 'valid-test-api-key-12345',
        'api_secret' => 'valid-test-api-secret-12345',
        'country' => 'ES',
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['redirect_url', 'connection_id']);

    $this->assertDatabaseHas('banking_connections', [
        'user_id' => $user->id,
        'provider' => 'binance',
        'aspsp_name' => 'Binance',
        'aspsp_country' => 'ES',
        'status' => BankingConnectionStatus::Active->value,
    ]);

    $this->assertDatabaseHas('accounts', [
        'user_id' => $user->id,
        'external_account_id' => 'binance-portfolio',
        'type' => AccountType::Investment->value,
        'currency_code' => 'EUR',
        'name' => 'Crypto Portfolio',
    ]);

    Queue::assertPushed(SyncBankingConnectionJob::class);
});

test('invalid binance credentials return 422', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('open-banking');

    Http::fake([
        'api.binance.com/api/v3/account*' => Http::response(['msg' => 'Invalid API-key'], 401),
    ]);

    $response = $this->actingAs($user)->postJson('/open-banking/binance/connect', [
        'api_key' => 'invalid-api-key-12345',
        'api_secret' => 'invalid-api-secret-12345',
        'country' => 'ES',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonFragment(['message' => 'Invalid API credentials or failed to connect to Binance.']);

    $this->assertDatabaseMissing('banking_connections', [
        'user_id' => $user->id,
        'provider' => 'binance',
    ]);
});

test('binance connection with account-mapping flag returns mapping redirect', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    Feature::for($user)->activate('open-banking');
    Feature::for($user)->activate('account-mapping');

    Http::fake([
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'BTC', 'free' => '1.0', 'locked' => '0.0'],
            ],
        ]),
    ]);

    $response = $this->actingAs($user)->postJson('/open-banking/binance/connect', [
        'api_key' => 'valid-test-api-key-12345',
        'api_secret' => 'valid-test-api-secret-12345',
        'country' => 'ES',
    ]);

    $response->assertOk();

    $connection = BankingConnection::where('user_id', $user->id)->where('provider', 'binance')->first();

    expect($connection->status)->toBe(BankingConnectionStatus::AwaitingMapping);
    expect($connection->pending_accounts_data)->toHaveCount(1);
    expect($connection->pending_accounts_data[0]['uid'])->toBe('binance-portfolio');
    expect($connection->pending_accounts_data[0]['name'])->toBe('Crypto Portfolio');

    $this->assertDatabaseMissing('accounts', [
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
    ]);

    Queue::assertNothingPushed();
});

test('binance requires open-banking feature flag', function () {
    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)->postJson('/open-banking/binance/connect', [
        'api_key' => 'valid-test-api-key-12345',
        'api_secret' => 'valid-test-api-secret-12345',
        'country' => 'ES',
    ]);

    $response->assertNotFound();
});

test('binance api_key and api_secret are required and must be at least 10 characters', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('open-banking');

    $this->actingAs($user)->postJson('/open-banking/binance/connect', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['api_key', 'api_secret', 'country']);

    $this->actingAs($user)->postJson('/open-banking/binance/connect', [
        'api_key' => 'short',
        'api_secret' => 'short',
        'country' => 'ES',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['api_key', 'api_secret']);
});

test('binance creates single crypto portfolio account with user currency', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create(['currency_code' => 'USD']);
    Feature::for($user)->activate('open-banking');

    Http::fake([
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'BTC', 'free' => '0.5', 'locked' => '0.0'],
            ],
        ]),
    ]);

    $response = $this->actingAs($user)->postJson('/open-banking/binance/connect', [
        'api_key' => 'valid-test-api-key-12345',
        'api_secret' => 'valid-test-api-secret-12345',
        'country' => 'DE',
    ]);

    $response->assertOk();

    expect($user->accounts()->count())->toBe(1);

    $this->assertDatabaseHas('accounts', [
        'user_id' => $user->id,
        'name' => 'Crypto Portfolio',
        'currency_code' => 'USD',
        'type' => AccountType::Investment->value,
        'external_account_id' => 'binance-portfolio',
    ]);
});
