<?php

use App\Enums\AccountType;
use App\Enums\BankingConnectionStatus;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

test('users can connect a bitpanda account with valid credentials', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    Feature::for($user)->activate('open-banking');

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

    $this->assertDatabaseHas('banking_connections', [
        'user_id' => $user->id,
        'provider' => 'bitpanda',
        'aspsp_name' => 'Bitpanda',
        'aspsp_country' => 'ES',
        'status' => BankingConnectionStatus::Active->value,
    ]);

    $this->assertDatabaseHas('accounts', [
        'user_id' => $user->id,
        'external_account_id' => 'bitpanda-portfolio',
        'type' => AccountType::Investment->value,
        'currency_code' => 'EUR',
        'name' => 'Crypto Portfolio',
    ]);

    Queue::assertPushed(SyncBankingConnectionJob::class);
});

test('invalid bitpanda credentials return 422', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('open-banking');

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

test('bitpanda connection with account-mapping flag returns mapping redirect', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    Feature::for($user)->activate('open-banking');
    Feature::for($user)->activate('account-mapping');

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
    ]);

    $response = $this->actingAs($user)->postJson('/open-banking/bitpanda/connect', [
        'api_key' => 'valid-test-api-key-12345',
        'country' => 'ES',
    ]);

    $response->assertOk();

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

test('bitpanda requires open-banking feature flag', function () {
    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)->postJson('/open-banking/bitpanda/connect', [
        'api_key' => 'valid-test-api-key-12345',
        'country' => 'ES',
    ]);

    $response->assertNotFound();
});

test('bitpanda api_key is required and must be at least 10 characters', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('open-banking');

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

test('bitpanda creates single crypto portfolio account with user currency', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create(['currency_code' => 'USD']);
    Feature::for($user)->activate('open-banking');

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

    expect($user->accounts()->count())->toBe(1);

    $this->assertDatabaseHas('accounts', [
        'user_id' => $user->id,
        'name' => 'Crypto Portfolio',
        'currency_code' => 'USD',
        'type' => AccountType::Investment->value,
        'external_account_id' => 'bitpanda-portfolio',
    ]);
});
