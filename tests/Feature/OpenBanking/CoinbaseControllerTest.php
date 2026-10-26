<?php

use App\Enums\BankingConnectionStatus;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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
        'private_key' => testEcPrivateKey(),
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

test('coinbase request validates api_key_name format', function () {
    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)->postJson('/open-banking/coinbase/connect', [
        'api_key_name' => 'not-a-valid-format',
        'private_key' => testEcPrivateKey(),
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
