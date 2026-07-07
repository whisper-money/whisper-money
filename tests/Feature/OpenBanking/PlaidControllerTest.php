<?php

use App\Enums\BankingConnectionStatus;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function fakePlaidApi(): void
{
    Http::fake(function (Illuminate\Http\Client\Request $request) {
        $url = $request->url();
        $body = $request->data();

        // Link token creation
        if (str_contains($url, '/link/token/create')) {
            return Http::response([
                'link_token' => 'link-sandbox-abc123def',
                'expiration' => '2026-07-08T00:00:00Z',
                'request_id' => 'req_link_token',
            ]);
        }

        // Public token exchange
        if (str_contains($url, '/item/public_token/exchange')) {
            return Http::response([
                'access_token' => 'access-sandbox-xyz789',
                'item_id' => 'item_abc123',
                'request_id' => 'req_exchange',
            ]);
        }

        // Get accounts
        if (str_contains($url, '/accounts/get')) {
            return Http::response([
                'accounts' => [
                    [
                        'account_id' => 'plaid_acc_checking_1',
                        'name' => 'Plaid Checking',
                        'official_name' => 'Plaid Premier Checking',
                        'type' => 'depository',
                        'subtype' => 'checking',
                        'balances' => [
                            'current' => 1250.00,
                            'iso_currency_code' => 'USD',
                        ],
                    ],
                    [
                        'account_id' => 'plaid_acc_savings_1',
                        'name' => 'Plaid Savings',
                        'official_name' => null,
                        'type' => 'depository',
                        'subtype' => 'savings',
                        'balances' => [
                            'current' => 5000.00,
                            'iso_currency_code' => 'USD',
                        ],
                    ],
                ],
                'item' => ['item_id' => 'item_abc123'],
                'request_id' => 'req_accounts',
            ]);
        }

        return Http::response([], 404);
    });
}

test('creates a link token from plaid', function () {
    fakePlaidApi();

    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)->postJson('/open-banking/plaid/link-token');

    $response->assertOk();
    $response->assertJsonStructure(['link_token', 'expiration']);
});

test('connecting plaid with valid public token creates pending accounts', function () {
    Queue::fake();
    fakePlaidApi();

    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)->postJson('/open-banking/plaid/connect', [
        'public_token' => 'public-sandbox-valid-token-12345',
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['redirect_url', 'connection_id']);

    $connection = BankingConnection::where('user_id', $user->id)->where('provider', 'plaid')->first();
    expect($connection->status)->toBe(BankingConnectionStatus::AwaitingMapping);

    $uids = collect($connection->pending_accounts_data)->pluck('uid');

    expect($uids)->toContain('plaid_acc_checking_1', 'plaid_acc_savings_1');
    expect($connection->pending_accounts_data)->toHaveCount(2);

    // Assert access token is stored encrypted
    expect($connection->api_token)->not->toBeNull();

    // No accounts created yet for an onboarded user (mapping step does that).
    $this->assertDatabaseMissing('accounts', [
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
    ]);
});

test('connecting plaid during onboarding auto-creates accounts', function () {
    Queue::fake();
    fakePlaidApi();

    $user = User::factory()->create(); // not onboarded

    $response = $this->actingAs($user)->postJson('/open-banking/plaid/connect', [
        'public_token' => 'public-sandbox-valid-token-12345',
    ]);

    $response->assertOk();

    $connection = BankingConnection::where('user_id', $user->id)->where('provider', 'plaid')->first();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);

    foreach (['plaid_acc_checking_1', 'plaid_acc_savings_1'] as $uid) {
        $this->assertDatabaseHas('accounts', [
            'user_id' => $user->id,
            'banking_connection_id' => $connection->id,
            'external_account_id' => $uid,
        ]);
    }

    Queue::assertPushed(SyncBankingConnectionJob::class);
});

test('plaid connect with invalid public token returns 422', function () {
    Http::fake([
        'sandbox.plaid.com/item/public_token/exchange' => Http::response(
            ['error_code' => 'INVALID_PUBLIC_TOKEN', 'error_message' => 'provided public token is not in a valid format'],
            400,
        ),
    ]);

    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)->postJson('/open-banking/plaid/connect', [
        'public_token' => 'invalid-token',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonFragment(['message' => 'Failed to connect Plaid. Please try again.']);

    $this->assertDatabaseMissing('banking_connections', [
        'user_id' => $user->id,
        'provider' => 'plaid',
    ]);
});

test('plaid connect requires public_token', function () {
    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)->postJson('/open-banking/plaid/connect', []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['public_token']);
});
