<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingConnectionStatus;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

beforeEach(function () {
    config([
        'services.enablebanking.app_id' => 'test-app-id',
        'services.enablebanking.private_key_path' => '/tmp/fake-key.pem',
        'services.enablebanking.redirect_url' => 'https://example.com/callback',
    ]);
});

test('users can start bank authorization', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('open-banking');

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('startAuthorization')
        ->once()
        ->andReturn([
            'url' => 'https://bank.example.com/authorize',
            'authorization_id' => 'auth-123',
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->postJson('/open-banking/authorize', [
        'aspsp_name' => 'Test Bank',
        'country' => 'ES',
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['redirect_url', 'connection_id']);

    $this->assertDatabaseHas('banking_connections', [
        'user_id' => $user->id,
        'provider' => 'enablebanking',
        'aspsp_name' => 'Test Bank',
        'aspsp_country' => 'ES',
        'status' => BankingConnectionStatus::Pending->value,
    ]);
});

test('free tier users cannot start bank authorization when subscriptions are enabled', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('open-banking');

    $response = $this->actingAs($user)->postJson('/open-banking/authorize', [
        'aspsp_name' => 'Test Bank',
        'country' => 'ES',
    ]);

    $response->assertStatus(402);
    $response->assertJson(['redirect' => route('subscribe')]);

    $this->assertDatabaseMissing('banking_connections', [
        'user_id' => $user->id,
    ]);
});

test('subscribed users can start bank authorization when subscriptions are enabled', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->onboarded()->create();
    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test_auth',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
    ]);
    Feature::for($user)->activate('open-banking');

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('startAuthorization')
        ->once()
        ->andReturn([
            'url' => 'https://bank.example.com/authorize',
            'authorization_id' => 'auth-456',
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->postJson('/open-banking/authorize', [
        'aspsp_name' => 'Test Bank',
        'country' => 'ES',
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['redirect_url', 'connection_id']);
});

test('authorization requires aspsp_name and country', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('open-banking');

    $response = $this->actingAs($user)->postJson('/open-banking/authorize', []);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['aspsp_name', 'country']);
});

test('callback with error redirects with error message and deletes pending connection', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('open-banking');

    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)
        ->get('/open-banking/callback?error=access_denied&error_description=User+denied+access');

    $response->assertRedirect(route('settings.connections.index'));
    $response->assertSessionHas('error', 'User denied access');

    $connection->refresh();
    expect($connection->trashed())->toBeTrue();
});

test('callback without code redirects with error', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('open-banking');

    $response = $this->actingAs($user)->get('/open-banking/callback');

    $response->assertRedirect(route('settings.connections.index'));
    $response->assertSessionHas('error');
});

test('callback with valid code creates accounts directly when account-mapping is disabled', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('open-banking');

    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Test Bank',
        'aspsp_country' => 'ES',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->with('test-code')
        ->once()
        ->andReturn([
            'session_id' => 'session-123',
            'accounts' => [
                [
                    'uid' => 'ext-account-1',
                    'currency' => 'EUR',
                    'name' => 'My Checking Account',
                    'account_id' => ['iban' => 'ES1234567890123456789012'],
                ],
            ],
            'aspsp' => ['name' => 'Test Bank', 'country' => 'ES'],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    $response->assertRedirect(route('settings.connections.index'));

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->session_id)->toBe('session-123');

    $this->assertDatabaseHas('accounts', [
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-account-1',
        'encrypted' => false,
    ]);

    Queue::assertPushed(SyncBankingConnectionJob::class);
});

test('callback with valid code stores pending accounts and redirects to mapping when account-mapping is enabled', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('open-banking');
    Feature::for($user)->activate('account-mapping');

    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Test Bank',
        'aspsp_country' => 'ES',
    ]);

    $accounts = [
        [
            'uid' => 'ext-account-1',
            'currency' => 'EUR',
            'name' => 'My Checking Account',
            'account_id' => ['iban' => 'ES1234567890123456789012'],
        ],
    ];

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->with('test-code')
        ->once()
        ->andReturn([
            'session_id' => 'session-123',
            'accounts' => $accounts,
            'aspsp' => ['name' => 'Test Bank', 'country' => 'ES'],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    $response->assertRedirect(route('open-banking.map-accounts', $connection));

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::AwaitingMapping);
    expect($connection->session_id)->toBe('session-123');
    expect($connection->pending_accounts_data)->toEqual($accounts);

    $this->assertDatabaseMissing('accounts', [
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
    ]);

    Queue::assertNothingPushed();
});
