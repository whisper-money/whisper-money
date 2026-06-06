<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingConnectionStatus;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'services.enablebanking.app_id' => 'test-app-id',
        'services.enablebanking.private_key_path' => '/tmp/fake-key.pem',
        'services.enablebanking.redirect_url' => 'https://example.com/callback',
    ]);
});

test('users can start bank authorization', function () {
    $user = User::factory()->onboarded()->create();
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

test('users can start bank authorization during onboarding when subscriptions are enabled', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->notOnboarded()->create();
    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('startAuthorization')
        ->once()
        ->andReturn([
            'url' => 'https://bank.example.com/authorize',
            'authorization_id' => 'auth-onboarding-123',
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

test('subscribed users can start bank authorization when subscriptions are enabled', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->onboarded()->create();
    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test_auth',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
    ]);
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
    $response = $this->actingAs($user)->postJson('/open-banking/authorize', []);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['aspsp_name', 'country']);
});

test('callback with error redirects with error message and deletes pending connection', function () {
    $user = User::factory()->onboarded()->create();
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

test('callback with error during onboarding redirects to the accounts step', function () {
    $user = User::factory()->notOnboarded()->create();
    Account::factory()->create(['user_id' => $user->id]);
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)
        ->get('/open-banking/callback?error=access_denied&error_description=User+denied+access');

    $response->assertRedirect(route('onboarding', ['step' => 'create-account']));
    $response->assertSessionHas('error', 'User denied access');

    $connection->refresh();
    expect($connection->trashed())->toBeTrue();
});

test('callback with error during reconnect preserves existing connection', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'ING',
        'aspsp_country' => 'ES',
    ]);

    Account::factory()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
    ]);

    $response = $this->actingAs($user)
        ->get('/open-banking/callback?error=access_denied&error_description=User+denied+access');

    $response->assertRedirect(route('settings.connections.index'));
    $response->assertSessionHas('error', 'User denied access');

    $connection->refresh();
    expect($connection->trashed())->toBeFalse();
    expect($connection->status)->toBe(BankingConnectionStatus::Error);
    expect($connection->error_message)->toBe('User denied access');
});

test('callback without code redirects with error', function () {
    $user = User::factory()->onboarded()->create();
    $response = $this->actingAs($user)->get('/open-banking/callback');

    $response->assertRedirect(route('settings.connections.index'));
    $response->assertSessionHas('error');
});

test('callback with valid code stores pending accounts and redirects to mapping', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
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

test('callback during onboarding redirects a logged-in user directly to the connections step', function () {
    Queue::fake();

    $user = User::factory()->notOnboarded()->create();
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
            'session_id' => 'session-onboarding',
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

    $response->assertRedirect(route('onboarding', ['step' => 'create-account']));

    $this->assertDatabaseHas('accounts', [
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
    ]);
});

// Reauthorize tests

test('reauthorize returns 403 when user does not own the connection', function () {
    $owner = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->error()->create([
        'user_id' => $owner->id,
    ]);

    $response = $this->actingAs($other)->postJson("/open-banking/connections/{$connection->id}/reauthorize");

    $response->assertForbidden();
});

test('reauthorize returns 422 for non-EnableBanking connections', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->indexaCapital()->error()->create([
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->postJson("/open-banking/connections/{$connection->id}/reauthorize");

    $response->assertUnprocessable();
    $response->assertJson(['error' => 'Only EnableBanking connections can be re-authorized.']);
});

test('reauthorize returns 422 for active connections', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'status' => BankingConnectionStatus::Active,
    ]);

    $response = $this->actingAs($user)->postJson("/open-banking/connections/{$connection->id}/reauthorize");

    $response->assertUnprocessable();
    $response->assertJson(['error' => 'Only connections with an error or expired status can be re-authorized.']);
});

test('reauthorize starts new authorization and sets connection to pending for error connections', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->error()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'CaixaBank',
        'aspsp_country' => 'ES',
        'error_message' => 'Authentication failed. Your credentials may have expired or been revoked.',
    ]);

    $originalAuthorizationId = $connection->authorization_id;

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('startAuthorization')
        ->with('CaixaBank', 'ES', config('services.enablebanking.redirect_url'), Mockery::type('string'))
        ->once()
        ->andReturn([
            'url' => 'https://bank.example.com/reauthorize',
            'authorization_id' => 'new-auth-id-456',
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->postJson("/open-banking/connections/{$connection->id}/reauthorize");

    $response->assertOk();
    $response->assertJsonStructure(['redirect_url', 'connection_id']);
    $response->assertJson([
        'redirect_url' => 'https://bank.example.com/reauthorize',
        'connection_id' => $connection->id,
    ]);

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Pending);
    expect($connection->authorization_id)->toBe('new-auth-id-456');
    expect($connection->authorization_id)->not->toBe($originalAuthorizationId);
    expect($connection->error_message)->toBeNull();
});

test('reauthorize starts new authorization for expired connections', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->expired()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Santander',
        'aspsp_country' => 'ES',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('startAuthorization')
        ->once()
        ->andReturn([
            'url' => 'https://bank.example.com/reauthorize',
            'authorization_id' => 'new-auth-id-789',
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->postJson("/open-banking/connections/{$connection->id}/reauthorize");

    $response->assertOk();

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Pending);
    expect($connection->authorization_id)->toBe('new-auth-id-789');
});

test('reconnect link redirects expired connections to bank authorization', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->expired()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Santander',
        'aspsp_country' => 'ES',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('startAuthorization')
        ->with('Santander', 'ES', config('services.enablebanking.redirect_url'), Mockery::type('string'))
        ->once()
        ->andReturn([
            'url' => 'https://bank.example.com/reauthorize',
            'authorization_id' => 'new-auth-id-987',
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->get(route('open-banking.reconnect', $connection));

    $response->assertRedirect('https://bank.example.com/reauthorize');

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Pending);
    expect($connection->authorization_id)->toBe('new-auth-id-987');
});

test('free tier users cannot reauthorize after onboarding when subscriptions are enabled', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->error()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'CaixaBank',
        'aspsp_country' => 'ES',
        'error_message' => 'Authentication failed. Your credentials may have expired or been revoked.',
    ]);

    $response = $this->actingAs($user)->postJson("/open-banking/connections/{$connection->id}/reauthorize");

    $response->assertStatus(402);
    $response->assertJson(['redirect' => route('subscribe')]);
});

// Reconnect callback tests

test('callback with existing accounts updates session without creating new accounts', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'CaixaBank',
        'aspsp_country' => 'ES',
        'last_synced_at' => now()->subWeek(),
    ]);

    Account::factory()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'existing-ext-account-1',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->with('test-code')
        ->once()
        ->andReturn([
            'session_id' => 'new-session-456',
            'accounts' => [
                [
                    'uid' => 'existing-ext-account-1',
                    'currency' => 'EUR',
                    'name' => 'CaixaBank Account',
                    'account_id' => ['iban' => 'ES1234567890123456789012'],
                ],
            ],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    $response->assertRedirect(route('settings.connections.index'));
    $response->assertSessionHas('success', 'Bank account reconnected successfully.');

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->session_id)->toBe('new-session-456');
    expect($connection->error_message)->toBeNull();

    // No duplicate accounts should have been created
    $this->assertDatabaseCount('accounts', 1);

    Queue::assertPushed(SyncBankingConnectionJob::class);
});

test('callback with existing accounts skips mapping on reconnect', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'CaixaBank',
        'aspsp_country' => 'ES',
    ]);

    Account::factory()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'existing-ext-account-1',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->once()
        ->andReturn([
            'session_id' => 'new-session-789',
            'accounts' => [],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    // Must redirect to connections page, NOT to the account-mapping route
    $response->assertRedirect(route('settings.connections.index'));

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);

    Queue::assertPushed(SyncBankingConnectionJob::class);
});

test('callback matches the pending reconnect by institution when multiple reconnects are open', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $bbvaConnection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'BBVA',
        'aspsp_country' => 'ES',
        'created_at' => now()->subHours(2),
    ]);
    $ingConnection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'ING',
        'aspsp_country' => 'ES',
        'created_at' => now()->subHour(),
    ]);

    $bbvaAccount = Account::factory()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $bbvaConnection->id,
        'external_account_id' => 'old-bbva-uid',
        'iban' => 'ES0000000000000000008058',
    ]);
    $ingAccount = Account::factory()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $ingConnection->id,
        'external_account_id' => 'old-ing-uid',
        'iban' => 'ES0000000000000000001111',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->once()
        ->andReturn([
            'session_id' => 'new-bbva-session',
            'accounts' => [
                [
                    'uid' => 'new-bbva-uid',
                    'currency' => 'EUR',
                    'name' => 'BBVA Account',
                    'account_id' => ['iban' => 'ES0000000000000000008058'],
                ],
            ],
            'aspsp' => ['name' => 'BBVA', 'country' => 'ES'],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    $response->assertRedirect(route('settings.connections.index'));

    expect($bbvaConnection->refresh()->status)->toBe(BankingConnectionStatus::Active);
    expect($bbvaConnection->session_id)->toBe('new-bbva-session');
    expect($bbvaAccount->refresh()->external_account_id)->toBe('new-bbva-uid');
    expect($ingConnection->refresh()->status)->toBe(BankingConnectionStatus::Pending);
    expect($ingConnection->session_id)->toBeNull();
    expect($ingAccount->refresh()->external_account_id)->toBe('old-ing-uid');

    Queue::assertPushed(SyncBankingConnectionJob::class, fn (SyncBankingConnectionJob $job): bool => $job->bankingConnection->id === $bbvaConnection->id);
});

// refreshAccountIds tests

test('reconnect callback updates external_account_id when enable banking issues new account uids', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'CaixaBank',
        'aspsp_country' => 'ES',
    ]);

    $account = Account::factory()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'old-uid-1',
        'iban' => 'ES1234567890123456789012',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->with('test-code')
        ->once()
        ->andReturn([
            'session_id' => 'new-session-abc',
            'accounts' => [
                [
                    'uid' => 'new-uid-1',
                    'currency' => 'EUR',
                    'name' => 'CaixaBank Account',
                    'account_id' => ['iban' => 'ES1234567890123456789012'],
                ],
            ],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    $account->refresh();
    expect($account->external_account_id)->toBe('new-uid-1');
    expect($account->iban)->toBe('ES1234567890123456789012');
});

test('reconnect callback matches accounts by iban before falling back to position', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'CaixaBank',
        'aspsp_country' => 'ES',
    ]);

    // Two accounts; create them in reverse IBAN order to confirm positional matching
    // would produce wrong results, while IBAN matching produces correct results.
    $accountA = Account::factory()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'old-uid-a',
        'iban' => 'ES0000000000000000000001',
        'created_at' => now()->subMinutes(2),
    ]);

    $accountB = Account::factory()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'old-uid-b',
        'iban' => 'ES0000000000000000000002',
        'created_at' => now()->subMinutes(1),
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->once()
        ->andReturn([
            'session_id' => 'new-session-abc',
            // Enable Banking returns accounts in a different order this time
            'accounts' => [
                [
                    'uid' => 'new-uid-b',
                    'currency' => 'EUR',
                    'name' => 'Account B',
                    'account_id' => ['iban' => 'ES0000000000000000000002'],
                ],
                [
                    'uid' => 'new-uid-a',
                    'currency' => 'EUR',
                    'name' => 'Account A',
                    'account_id' => ['iban' => 'ES0000000000000000000001'],
                ],
            ],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    expect($accountA->refresh()->external_account_id)->toBe('new-uid-a');
    expect($accountB->refresh()->external_account_id)->toBe('new-uid-b');
});

test('reconnect callback uses positional fallback for accounts without stored iban', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'CaixaBank',
        'aspsp_country' => 'ES',
    ]);

    $accountA = Account::factory()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'old-uid-a',
        'iban' => null, // legacy account without IBAN stored
        'created_at' => now()->subMinutes(2),
    ]);

    $accountB = Account::factory()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'old-uid-b',
        'iban' => null,
        'created_at' => now()->subMinutes(1),
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->once()
        ->andReturn([
            'session_id' => 'new-session-abc',
            'accounts' => [
                [
                    'uid' => 'new-uid-a',
                    'currency' => 'EUR',
                    'name' => 'Account A',
                    'account_id' => ['iban' => 'ES0000000000000000000001'],
                ],
                [
                    'uid' => 'new-uid-b',
                    'currency' => 'EUR',
                    'name' => 'Account B',
                    'account_id' => ['iban' => 'ES0000000000000000000002'],
                ],
            ],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    // Positional match: index 0 → accountA (oldest), index 1 → accountB
    expect($accountA->refresh()->external_account_id)->toBe('new-uid-a');
    expect($accountA->refresh()->iban)->toBe('ES0000000000000000000001'); // IBAN populated from new session
    expect($accountB->refresh()->external_account_id)->toBe('new-uid-b');
    expect($accountB->refresh()->iban)->toBe('ES0000000000000000000002');
});

test('callback stores iban in pending accounts data', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
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
            'session_id' => 'session-new',
            'accounts' => [
                [
                    'uid' => 'ext-account-1',
                    'currency' => 'EUR',
                    'name' => 'My Account',
                    'account_id' => ['iban' => 'ES9999999999999999999999'],
                ],
            ],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    $connection->refresh();
    expect($connection->pending_accounts_data)->toHaveCount(1);
    expect($connection->pending_accounts_data[0]['account_id']['iban'])->toBe('ES9999999999999999999999');
});

// Authless callback via state token (iOS PWA returns to Safari without a session)

test('start authorization persists a state token and sends it to the provider', function () {
    $user = User::factory()->onboarded()->create();

    $capturedState = null;
    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('startAuthorization')
        ->once()
        ->withArgs(function (string $name, string $country, string $url, string $state) use (&$capturedState): bool {
            $capturedState = $state;

            return $name === 'Test Bank' && $country === 'ES' && $state !== '';
        })
        ->andReturn([
            'url' => 'https://bank.example.com/authorize',
            'authorization_id' => 'auth-state-1',
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->actingAs($user)->postJson('/open-banking/authorize', [
        'aspsp_name' => 'Test Bank',
        'country' => 'ES',
    ])->assertOk();

    $connection = $user->bankingConnections()->first();
    expect($connection->state_token)->not->toBeEmpty();
    expect($connection->state_token)->toBe($capturedState);
});

test('callback finalizes the connection from the state token without an authenticated session', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'CaixaBank',
        'aspsp_country' => 'ES',
        'state_token' => 'state-token-abc',
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
            'session_id' => 'session-authless',
            'accounts' => $accounts,
            'aspsp' => ['name' => 'CaixaBank', 'country' => 'ES'],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    // No actingAs(): the PWA handed the redirect to Safari, which has no session.
    $response = $this->get('/open-banking/callback?code=test-code&state=state-token-abc');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('open-banking/connection-complete')
        ->where('status', 'success')
    );

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::AwaitingMapping);
    expect($connection->session_id)->toBe('session-authless');
    expect($connection->state_token)->toBeNull();
});

test('callback resolves the connection owner from the state token regardless of who is authenticated', function () {
    Queue::fake();

    $owner = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();

    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $owner->id,
        'aspsp_name' => 'CaixaBank',
        'aspsp_country' => 'ES',
        'state_token' => 'state-token-owner',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->with('test-code')
        ->once()
        ->andReturn([
            'session_id' => 'session-owner',
            'accounts' => [
                [
                    'uid' => 'ext-account-1',
                    'currency' => 'EUR',
                    'name' => 'Owner Account',
                    'account_id' => ['iban' => 'ES1234567890123456789012'],
                ],
            ],
            'aspsp' => ['name' => 'CaixaBank', 'country' => 'ES'],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($other)->get('/open-banking/callback?code=test-code&state=state-token-owner');

    $response->assertRedirect(route('open-banking.map-accounts', $connection));

    $connection->refresh();
    expect($connection->user_id)->toBe($owner->id);
    expect($connection->status)->toBe(BankingConnectionStatus::AwaitingMapping);
    expect($connection->session_id)->toBe('session-owner');
    expect($connection->state_token)->toBeNull();
});

test('callback without a session or a resolvable state redirects to login', function () {
    $response = $this->get('/open-banking/callback?code=test-code&state=unknown-token');

    $response->assertRedirect(route('login'));
});

test('callback renders the completion page on error without an authenticated session', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'state_token' => 'state-token-error',
    ]);

    // No actingAs(): the error came back to Safari, which has no session.
    $response = $this->get('/open-banking/callback?error=access_denied&error_description=Denied&state=state-token-error');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('open-banking/connection-complete')
        ->where('status', 'error')
        ->where('message', 'Denied')
    );

    expect($connection->fresh()->trashed())->toBeTrue();
});
