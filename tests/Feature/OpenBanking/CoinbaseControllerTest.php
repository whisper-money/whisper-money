<?php

use App\Enums\BankingConnectionStatus;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function generateTestEcPrivateKey(): string
{
    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);

    openssl_pkey_export($key, $pem);

    return $pem;
}

test('users can connect a coinbase account with valid credentials', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);

    Http::fake([
        'api.coinbase.com/api/v3/brokerage/accounts*' => Http::response([
            'accounts' => [
                [
                    'uuid' => 'cb-acc-1',
                    'name' => 'BTC Wallet',
                    'currency' => 'BTC',
                    'available_balance' => ['value' => '0.5', 'currency' => 'BTC'],
                    'hold' => ['value' => '0', 'currency' => 'BTC'],
                    'active' => true,
                    'type' => 'ACCOUNT_TYPE_CRYPTO',
                ],
            ],
            'has_next' => false,
            'cursor' => '',
            'size' => 1,
        ]),
    ]);

    $response = $this->actingAs($user)->postJson('/open-banking/coinbase/connect', [
        'api_key_name' => 'organizations/org-uuid/apiKeys/key-uuid',
        'private_key' => generateTestEcPrivateKey(),
        'country' => 'ES',
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['redirect_url', 'connection_id']);

    $connection = BankingConnection::where('user_id', $user->id)->where('provider', 'coinbase')->first();

    expect($connection->status)->toBe(BankingConnectionStatus::AwaitingMapping);
    expect($connection->pending_accounts_data)->toHaveCount(1);
    expect($connection->pending_accounts_data[0]['uid'])->toBe('coinbase-portfolio');
    expect($connection->pending_accounts_data[0]['name'])->toBe('Crypto Portfolio');

    $this->assertDatabaseMissing('accounts', [
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
    ]);

    Queue::assertNothingPushed();
});

test('invalid coinbase credentials return 422', function () {
    $user = User::factory()->onboarded()->create();

    Http::fake([
        'api.coinbase.com/api/v3/brokerage/accounts*' => Http::response(['error' => 'unauthorized'], 401),
    ]);

    $response = $this->actingAs($user)->postJson('/open-banking/coinbase/connect', [
        'api_key_name' => 'organizations/org-uuid/apiKeys/key-uuid',
        'private_key' => generateTestEcPrivateKey(),
        'country' => 'ES',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonFragment(['message' => 'Invalid API credentials or failed to connect to Coinbase.']);

    $this->assertDatabaseMissing('banking_connections', [
        'user_id' => $user->id,
        'provider' => 'coinbase',
    ]);
});

test('coinbase request validates api_key_name format', function () {
    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)->postJson('/open-banking/coinbase/connect', [
        'api_key_name' => 'not-a-valid-format',
        'private_key' => generateTestEcPrivateKey(),
        'country' => 'ES',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['api_key_name']);
});

test('coinbase request validates private_key length', function () {
    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)->postJson('/open-banking/coinbase/connect', [
        'api_key_name' => 'organizations/org-uuid/apiKeys/key-uuid',
        'private_key' => 'short',
        'country' => 'ES',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['private_key']);
});

test('free tier users cannot connect a coinbase account after onboarding when subscriptions are enabled', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)->postJson('/open-banking/coinbase/connect', [
        'api_key_name' => 'organizations/org-uuid/apiKeys/key-uuid',
        'private_key' => generateTestEcPrivateKey(),
        'country' => 'ES',
    ]);

    $response->assertPaymentRequired();
});
