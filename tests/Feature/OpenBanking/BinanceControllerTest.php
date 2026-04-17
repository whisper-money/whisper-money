<?php

use App\Enums\BankingConnectionStatus;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('users can connect a binance account with valid credentials', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
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

test('invalid binance credentials return 422', function () {
    $user = User::factory()->onboarded()->create();
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

test('free tier users cannot connect a binance account after onboarding when subscriptions are enabled', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)->postJson('/open-banking/binance/connect', [
        'api_key' => 'valid-test-api-key-12345',
        'api_secret' => 'valid-test-api-secret-12345',
        'country' => 'ES',
    ]);

    $response->assertStatus(402);
    $response->assertJson(['redirect' => route('subscribe')]);

    $this->assertDatabaseMissing('banking_connections', [
        'user_id' => $user->id,
        'provider' => 'binance',
    ]);
});

test('binance requires authentication', function () {
    $response = $this->postJson('/open-banking/binance/connect', [
        'api_key' => 'valid-test-api-key-12345',
        'api_secret' => 'valid-test-api-secret-12345',
        'country' => 'ES',
    ]);

    $response->assertUnauthorized();
});

test('binance api_key and api_secret are required and must be at least 10 characters', function () {
    $user = User::factory()->onboarded()->create();
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

test('binance stores pending accounts with user currency', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create(['currency_code' => 'USD']);
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

    $connection = BankingConnection::where('user_id', $user->id)->where('provider', 'binance')->first();

    expect($connection->status)->toBe(BankingConnectionStatus::AwaitingMapping);
    expect($connection->pending_accounts_data)->toHaveCount(1);
    expect($connection->pending_accounts_data[0]['currency'])->toBe('USD');
});

test('binance auto-creates accounts during onboarding', function () {
    config(['subscriptions.enabled' => true]);

    Queue::fake();

    $user = User::factory()->notOnboarded()->create(['currency_code' => 'EUR']);
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
    $response->assertJsonPath('redirect_url', route('onboarding', ['step' => 'create-account']));

    $connection = BankingConnection::where('user_id', $user->id)->where('provider', 'binance')->first();

    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->pending_accounts_data)->toBeNull();

    $this->assertDatabaseHas('accounts', [
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'binance-portfolio',
        'type' => 'investment',
    ]);

    Queue::assertPushed(SyncBankingConnectionJob::class);
});
