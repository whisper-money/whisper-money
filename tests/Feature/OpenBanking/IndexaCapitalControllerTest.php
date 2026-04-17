<?php

use App\Enums\BankingConnectionStatus;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Bank::factory()->create([
        'name' => 'Indexa Capital',
        'user_id' => null,
        'logo' => '/images/banks/logos/indexa-capital.jpg',
    ]);
});

test('users can connect an indexa capital account with valid token', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    Http::fake([
        'api.indexacapital.com/users/me' => Http::response([
            'accounts' => [
                ['account_number' => 'IC-001', 'status' => 'active', 'type' => 'mutual'],
            ],
        ]),
    ]);

    $response = $this->actingAs($user)->postJson('/open-banking/indexa-capital/connect', [
        'api_token' => 'valid-test-token-12345',
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['redirect_url', 'connection_id']);

    $connection = BankingConnection::where('user_id', $user->id)->where('provider', 'indexacapital')->first();

    expect($connection->status)->toBe(BankingConnectionStatus::AwaitingMapping);
    expect($connection->pending_accounts_data)->toHaveCount(1);
    expect($connection->pending_accounts_data[0]['uid'])->toBe('IC-001');

    $this->assertDatabaseMissing('accounts', [
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
    ]);

    Queue::assertNothingPushed();
});

test('invalid token returns 422', function () {
    $user = User::factory()->onboarded()->create();
    Http::fake([
        'api.indexacapital.com/users/me' => Http::response(['message' => 'Unauthorized'], 401),
    ]);

    $response = $this->actingAs($user)->postJson('/open-banking/indexa-capital/connect', [
        'api_token' => 'invalid-token-12345',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonFragment(['message' => 'Invalid API token or failed to connect to Indexa Capital.']);

    $this->assertDatabaseMissing('banking_connections', [
        'user_id' => $user->id,
        'provider' => 'indexacapital',
    ]);
});

test('free tier users cannot connect an indexa capital account after onboarding when subscriptions are enabled', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)->postJson('/open-banking/indexa-capital/connect', [
        'api_token' => 'valid-test-token-12345',
    ]);

    $response->assertStatus(402);
    $response->assertJson(['redirect' => route('subscribe')]);

    $this->assertDatabaseMissing('banking_connections', [
        'user_id' => $user->id,
        'provider' => 'indexacapital',
    ]);
});

test('indexa capital requires authentication', function () {
    $response = $this->postJson('/open-banking/indexa-capital/connect', [
        'api_token' => 'valid-test-token-12345',
    ]);

    $response->assertUnauthorized();
});

test('api_token is required and must be at least 10 characters', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user)->postJson('/open-banking/indexa-capital/connect', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['api_token']);

    $this->actingAs($user)->postJson('/open-banking/indexa-capital/connect', [
        'api_token' => 'short',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['api_token']);
});

test('stores multiple pending accounts for multiple indexa portfolios', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    Http::fake([
        'api.indexacapital.com/users/me' => Http::response([
            'accounts' => [
                ['account_number' => 'IC-001', 'status' => 'active', 'type' => 'mutual'],
                ['account_number' => 'IC-002', 'status' => 'active', 'type' => 'pension'],
            ],
        ]),
    ]);

    $response = $this->actingAs($user)->postJson('/open-banking/indexa-capital/connect', [
        'api_token' => 'valid-test-token-12345',
    ]);

    $response->assertOk();

    $connection = BankingConnection::where('user_id', $user->id)->where('provider', 'indexacapital')->first();

    expect($connection->status)->toBe(BankingConnectionStatus::AwaitingMapping);
    expect($connection->pending_accounts_data)->toHaveCount(2);
    expect($connection->pending_accounts_data[0]['uid'])->toBe('IC-001');
    expect($connection->pending_accounts_data[0]['name'])->toBe('Investment Portfolio (IC-001)');
    expect($connection->pending_accounts_data[1]['uid'])->toBe('IC-002');
    expect($connection->pending_accounts_data[1]['name'])->toBe('Pension Plan (IC-002)');
});

test('indexa capital auto-creates accounts during onboarding', function () {
    config(['subscriptions.enabled' => true]);

    Queue::fake();

    $user = User::factory()->notOnboarded()->create();
    Http::fake([
        'api.indexacapital.com/users/me' => Http::response([
            'accounts' => [
                ['account_number' => 'IC-001', 'status' => 'active', 'type' => 'mutual'],
                ['account_number' => 'IC-002', 'status' => 'active', 'type' => 'pension'],
            ],
        ]),
    ]);

    $response = $this->actingAs($user)->postJson('/open-banking/indexa-capital/connect', [
        'api_token' => 'valid-test-token-12345',
    ]);

    $response->assertOk();
    $response->assertJsonPath('redirect_url', route('onboarding', ['step' => 'create-account']));

    $connection = BankingConnection::where('user_id', $user->id)->where('provider', 'indexacapital')->first();

    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->pending_accounts_data)->toBeNull();

    $this->assertDatabaseHas('accounts', [
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'IC-001',
        'type' => 'investment',
    ]);

    $this->assertDatabaseHas('accounts', [
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'IC-002',
        'type' => 'investment',
    ]);

    Queue::assertPushed(SyncBankingConnectionJob::class);
});
