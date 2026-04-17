<?php

use App\Enums\BankingConnectionStatus;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('users can connect a bitpanda account with valid credentials', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    Http::fake([
        'api.bitpanda.com/v1/wallets' => Http::response([
            'data' => [
                [
                    'type' => 'wallet',
                    'attributes' => [
                        'cryptocoin_id' => '1',
                        'cryptocoin_symbol' => 'BTC',
                        'balance' => '0.50000000',
                        'is_default' => true,
                        'name' => 'BTC wallet',
                        'deleted' => false,
                    ],
                    'id' => 'wallet-uuid-1',
                ],
            ],
        ]),
    ]);

    $response = $this->actingAs($user)->postJson('/open-banking/bitpanda/connect', [
        'api_key' => 'valid-test-api-key-12345',
        'country' => 'ES',
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['redirect_url', 'connection_id']);

    $connection = BankingConnection::where('user_id', $user->id)->where('provider', 'bitpanda')->first();

    expect($connection->status)->toBe(BankingConnectionStatus::AwaitingMapping);
    expect($connection->pending_accounts_data)->toHaveCount(1);
    expect($connection->pending_accounts_data[0]['uid'])->toBe('bitpanda-portfolio');
    expect($connection->pending_accounts_data[0]['name'])->toBe('Crypto Portfolio');

    $this->assertDatabaseMissing('accounts', [
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
    ]);

    Queue::assertNothingPushed();
});

test('invalid bitpanda credentials return 422', function () {
    $user = User::factory()->onboarded()->create();
    Http::fake([
        'api.bitpanda.com/v1/wallets' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    $response = $this->actingAs($user)->postJson('/open-banking/bitpanda/connect', [
        'api_key' => 'invalid-api-key-12345',
        'country' => 'ES',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonFragment(['message' => 'Invalid API key or failed to connect to Bitpanda.']);

    $this->assertDatabaseMissing('banking_connections', [
        'user_id' => $user->id,
        'provider' => 'bitpanda',
    ]);
});

test('free tier users cannot connect a bitpanda account after onboarding when subscriptions are enabled', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)->postJson('/open-banking/bitpanda/connect', [
        'api_key' => 'valid-test-api-key-12345',
        'country' => 'ES',
    ]);

    $response->assertStatus(402);
    $response->assertJson(['redirect' => route('subscribe')]);

    $this->assertDatabaseMissing('banking_connections', [
        'user_id' => $user->id,
        'provider' => 'bitpanda',
    ]);
});

test('bitpanda requires authentication', function () {
    $response = $this->postJson('/open-banking/bitpanda/connect', [
        'api_key' => 'valid-test-api-key-12345',
        'country' => 'ES',
    ]);

    $response->assertUnauthorized();
});

test('bitpanda api_key is required and must be at least 10 characters', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user)->postJson('/open-banking/bitpanda/connect', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['api_key', 'country']);

    $this->actingAs($user)->postJson('/open-banking/bitpanda/connect', [
        'api_key' => 'short',
        'country' => 'ES',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['api_key']);
});

test('bitpanda stores pending accounts with user currency', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create(['currency_code' => 'USD']);
    Http::fake([
        'api.bitpanda.com/v1/wallets' => Http::response([
            'data' => [
                [
                    'type' => 'wallet',
                    'attributes' => [
                        'cryptocoin_id' => '1',
                        'cryptocoin_symbol' => 'BTC',
                        'balance' => '0.50000000',
                        'is_default' => true,
                        'name' => 'BTC wallet',
                        'deleted' => false,
                    ],
                    'id' => 'wallet-uuid-1',
                ],
            ],
        ]),
    ]);

    $response = $this->actingAs($user)->postJson('/open-banking/bitpanda/connect', [
        'api_key' => 'valid-test-api-key-12345',
        'country' => 'DE',
    ]);

    $response->assertOk();

    $connection = BankingConnection::where('user_id', $user->id)->where('provider', 'bitpanda')->first();

    expect($connection->status)->toBe(BankingConnectionStatus::AwaitingMapping);
    expect($connection->pending_accounts_data)->toHaveCount(1);
    expect($connection->pending_accounts_data[0]['currency'])->toBe('USD');
});

test('bitpanda auto-creates accounts during onboarding', function () {
    config(['subscriptions.enabled' => true]);

    Queue::fake();

    $user = User::factory()->notOnboarded()->create(['currency_code' => 'EUR']);
    Http::fake([
        'api.bitpanda.com/v1/wallets' => Http::response([
            'data' => [
                [
                    'type' => 'wallet',
                    'attributes' => [
                        'cryptocoin_id' => '1',
                        'cryptocoin_symbol' => 'BTC',
                        'balance' => '0.50000000',
                        'is_default' => true,
                        'name' => 'BTC wallet',
                        'deleted' => false,
                    ],
                    'id' => 'wallet-uuid-1',
                ],
            ],
        ]),
    ]);

    $response = $this->actingAs($user)->postJson('/open-banking/bitpanda/connect', [
        'api_key' => 'valid-test-api-key-12345',
        'country' => 'ES',
    ]);

    $response->assertOk();
    $response->assertJsonPath('redirect_url', route('onboarding', ['step' => 'create-account']));

    $connection = BankingConnection::where('user_id', $user->id)->where('provider', 'bitpanda')->first();

    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->pending_accounts_data)->toBeNull();

    $this->assertDatabaseHas('accounts', [
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'bitpanda-portfolio',
        'type' => 'investment',
    ]);

    Queue::assertPushed(SyncBankingConnectionJob::class);
});
