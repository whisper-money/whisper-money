<?php

use App\Enums\AccountType;
use App\Enums\BankingConnectionStatus;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

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
    Feature::for($user)->activate('open-banking');

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

    $this->assertDatabaseHas('banking_connections', [
        'user_id' => $user->id,
        'provider' => 'indexacapital',
        'aspsp_name' => 'Indexa Capital',
        'aspsp_country' => 'ES',
        'status' => BankingConnectionStatus::Active->value,
    ]);

    $this->assertDatabaseHas('accounts', [
        'user_id' => $user->id,
        'external_account_id' => 'IC-001',
        'type' => AccountType::Investment->value,
    ]);

    Queue::assertPushed(SyncBankingConnectionJob::class);
});

test('invalid token returns 422', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('open-banking');

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

test('connection with account-mapping flag returns mapping redirect', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('open-banking');
    Feature::for($user)->activate('account-mapping');

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
    expect($connection->pending_accounts_data[1]['uid'])->toBe('IC-002');

    $this->assertDatabaseMissing('accounts', [
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
    ]);

    Queue::assertNothingPushed();
});

test('requires open-banking feature flag', function () {
    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)->postJson('/open-banking/indexa-capital/connect', [
        'api_token' => 'valid-test-token-12345',
    ]);

    $response->assertNotFound();
});

test('api_token is required and must be at least 10 characters', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('open-banking');

    $this->actingAs($user)->postJson('/open-banking/indexa-capital/connect', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['api_token']);

    $this->actingAs($user)->postJson('/open-banking/indexa-capital/connect', [
        'api_token' => 'short',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['api_token']);
});

test('creates multiple accounts for multiple indexa portfolios', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('open-banking');

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

    expect($user->accounts()->where('type', AccountType::Investment->value)->count())->toBe(2);

    $this->assertDatabaseHas('accounts', [
        'user_id' => $user->id,
        'external_account_id' => 'IC-001',
        'name' => 'Investment Portfolio (IC-001)',
    ]);
    $this->assertDatabaseHas('accounts', [
        'user_id' => $user->id,
        'external_account_id' => 'IC-002',
        'name' => 'Pension Plan (IC-002)',
    ]);
});
