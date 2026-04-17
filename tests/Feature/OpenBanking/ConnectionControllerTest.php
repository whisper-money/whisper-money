<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingConnectionStatus;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\BankingConnection;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'services.enablebanking.app_id' => 'test-app-id',
        'services.enablebanking.private_key_path' => '/tmp/fake-key.pem',
        'services.enablebanking.redirect_url' => 'https://example.com/callback',
    ]);
});

test('users can view their connections page', function () {
    $user = User::factory()->onboarded()->create();
    BankingConnection::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get('/settings/connections');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/connections')
        ->has('connections', 1)
    );
});

test('connections page only shows own connections', function () {
    $user = User::factory()->onboarded()->create();
    $otherUser = User::factory()->onboarded()->create();
    BankingConnection::factory()->create(['user_id' => $user->id]);
    BankingConnection::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($user)->get('/settings/connections');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('connections', 1)
    );
});

test('users can disconnect a banking connection and keep accounts as manual', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);
    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);
    $balance = AccountBalance::factory()->create([
        'account_id' => $account->id,
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('revokeSession')->once();
    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->delete("/settings/connections/{$connection->id}", [
        'delete_accounts' => false,
        'confirmation' => null,
    ]);

    $response->assertRedirect(route('settings.connections.index'));

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Revoked);
    expect($connection->trashed())->toBeTrue();

    $account->refresh();
    expect($account->banking_connection_id)->toBeNull();
    expect($account->external_account_id)->toBeNull();
    expect($account->trashed())->toBeFalse();

    expect(Transaction::find($transaction->id))->not->toBeNull();
    expect(AccountBalance::find($balance->id))->not->toBeNull();
});

test('users can disconnect a banking connection and delete accounts', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);
    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);
    $balance = AccountBalance::factory()->create([
        'account_id' => $account->id,
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('revokeSession')->once();
    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->delete("/settings/connections/{$connection->id}", [
        'delete_accounts' => true,
        'confirmation' => 'delete all',
    ]);

    $response->assertRedirect(route('settings.connections.index'));

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Revoked);
    expect($connection->trashed())->toBeTrue();

    expect(Account::withTrashed()->find($account->id)->trashed())->toBeTrue();
    expect(Transaction::withTrashed()->find($transaction->id)->trashed())->toBeTrue();
    expect(AccountBalance::find($balance->id))->toBeNull();
});

test('deleting accounts requires confirmation text', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->delete("/settings/connections/{$connection->id}", [
        'delete_accounts' => true,
        'confirmation' => 'wrong text',
    ]);

    $response->assertSessionHasErrors('confirmation');
    expect($connection->fresh()->trashed())->toBeFalse();
});

test('users cannot disconnect another users connection', function () {
    $user = User::factory()->onboarded()->create();
    $otherUser = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($user)->delete("/settings/connections/{$connection->id}", [
        'delete_accounts' => false,
    ]);

    $response->assertForbidden();
});

test('users can trigger manual sync on active connection', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'status' => BankingConnectionStatus::Active,
    ]);

    $response = $this->actingAs($user)->post("/settings/connections/{$connection->id}/sync");

    $response->assertRedirect();
    $response->assertSessionHas('success');
});

test('free tier users are redirected to subscribe when syncing a connection after onboarding', function () {
    config(['subscriptions.enabled' => true]);

    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'status' => BankingConnectionStatus::Active,
    ]);

    $response = $this->actingAs($user)->post("/settings/connections/{$connection->id}/sync");

    $response->assertRedirect(route('subscribe'));

    Queue::assertNothingPushed();
});

test('disconnecting indexa capital connection does not revoke session', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->indexaCapital()->create(['user_id' => $user->id]);
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'IC-001',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldNotReceive('revokeSession');
    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->delete("/settings/connections/{$connection->id}", [
        'delete_accounts' => false,
        'confirmation' => null,
    ]);

    $response->assertRedirect(route('settings.connections.index'));

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Revoked);
    expect($connection->trashed())->toBeTrue();

    $account->refresh();
    expect($account->banking_connection_id)->toBeNull();
    expect($account->external_account_id)->toBeNull();
});

test('users cannot sync expired connection', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->expired()->create([
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->post("/settings/connections/{$connection->id}/sync");

    $response->assertRedirect();
    $response->assertSessionHas('error');
});

test('users can update indexa capital credentials with valid token', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->indexaCapital()->error()->create([
        'user_id' => $user->id,
        'error_message' => 'Authentication failed. Your credentials may have expired or been revoked.',
    ]);

    Http::fake([
        'api.indexacapital.com/users/me' => Http::response([
            'accounts' => [['account_number' => 'IC-001', 'status' => 'active', 'type' => 'mutual']],
        ]),
    ]);

    $response = $this->actingAs($user)->patch("/settings/connections/{$connection->id}/credentials", [
        'api_token' => 'new-valid-indexa-token-12345',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->error_message)->toBeNull();
    expect($connection->api_token)->toBe('new-valid-indexa-token-12345');
});

test('free tier users are redirected to subscribe when updating credentials after onboarding', function () {
    config(['subscriptions.enabled' => true]);

    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->indexaCapital()->error()->create([
        'user_id' => $user->id,
        'error_message' => 'Authentication failed. Your credentials may have expired or been revoked.',
    ]);

    $response = $this->actingAs($user)->patch("/settings/connections/{$connection->id}/credentials", [
        'api_token' => 'new-valid-indexa-token-12345',
    ]);

    $response->assertRedirect(route('subscribe'));

    Queue::assertNothingPushed();
});

test('users can update binance credentials with valid api key and secret', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->binance()->error()->create([
        'user_id' => $user->id,
        'error_message' => 'Authentication failed. Your credentials may have expired or been revoked.',
    ]);

    Http::fake([
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [['asset' => 'BTC', 'free' => '1.0', 'locked' => '0.0']],
        ]),
    ]);

    $response = $this->actingAs($user)->patch("/settings/connections/{$connection->id}/credentials", [
        'api_key' => 'new-valid-binance-key-12345',
        'api_secret' => 'new-valid-binance-secret-12345',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->error_message)->toBeNull();
    expect($connection->api_token)->toBe('new-valid-binance-key-12345');
    expect($connection->api_secret)->toBe('new-valid-binance-secret-12345');
});

test('users can update bitpanda credentials with valid api key', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->bitpanda()->error()->create([
        'user_id' => $user->id,
        'error_message' => 'Authentication failed. Your credentials may have expired or been revoked.',
    ]);

    Http::fake([
        'api.bitpanda.com/v1/wallets' => Http::response([
            'data' => [],
        ]),
    ]);

    $response = $this->actingAs($user)->patch("/settings/connections/{$connection->id}/credentials", [
        'api_key' => 'new-valid-bitpanda-key-12345',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->error_message)->toBeNull();
    expect($connection->api_token)->toBe('new-valid-bitpanda-key-12345');
});

test('updating credentials with invalid token returns validation error', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->indexaCapital()->error()->create([
        'user_id' => $user->id,
    ]);

    Http::fake([
        'api.indexacapital.com/users/me' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    $response = $this->actingAs($user)->patch("/settings/connections/{$connection->id}/credentials", [
        'api_token' => 'invalid-token-12345678',
    ]);

    $response->assertSessionHasErrors('credentials');

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Error);
});

test('cannot update credentials for enablebanking connection', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'provider' => 'enablebanking',
    ]);

    $response = $this->actingAs($user)->patch("/settings/connections/{$connection->id}/credentials", [
        'api_token' => 'some-token-value-12345',
    ]);

    $response->assertSessionHasErrors();
});

test('cannot update credentials for another users connection', function () {
    $user = User::factory()->onboarded()->create();
    $otherUser = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->indexaCapital()->create([
        'user_id' => $otherUser->id,
    ]);

    $response = $this->actingAs($user)->patch("/settings/connections/{$connection->id}/credentials", [
        'api_token' => 'some-token-value-12345',
    ]);

    $response->assertForbidden();
});

test('users can update credentials for their own connection', function () {
    $user = User::factory()->onboarded()->create();

    $connection = BankingConnection::factory()->indexaCapital()->create([
        'user_id' => $user->id,
    ]);

    Http::fake([
        'api.indexacapital.com/users/me' => Http::response([
            'accounts' => [],
        ]),
    ]);

    $response = $this->actingAs($user)->patch("/settings/connections/{$connection->id}/credentials", [
        'api_token' => 'some-token-value-12345',
    ]);

    $response->assertRedirect();
});

test('credential update validates required fields for binance', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->binance()->error()->create([
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->patch("/settings/connections/{$connection->id}/credentials", [
        'api_key' => 'valid-key-12345678',
        // missing api_secret
    ]);

    $response->assertSessionHasErrors('api_secret');
});

test('connections page includes provider and aspsp_name fields needed for frontend duplicate filtering', function () {
    $user = User::factory()->onboarded()->create();
    BankingConnection::factory()->create([
        'user_id' => $user->id,
        'provider' => 'enablebanking',
        'aspsp_name' => 'CaixaBank',
    ]);

    $response = $this->actingAs($user)->get('/settings/connections');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('connections', 1)
        ->where('connections.0.provider', 'enablebanking')
        ->where('connections.0.aspsp_name', 'CaixaBank')
    );
});

test('connections page includes connections from all provider types for frontend filtering', function () {
    $user = User::factory()->onboarded()->create();
    BankingConnection::factory()->create(['user_id' => $user->id, 'provider' => 'enablebanking', 'aspsp_name' => 'CaixaBank']);
    BankingConnection::factory()->binance()->create(['user_id' => $user->id]);
    BankingConnection::factory()->bitpanda()->create(['user_id' => $user->id]);
    BankingConnection::factory()->indexaCapital()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get('/settings/connections');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('connections', 4)
        ->has('connections.0.provider')
        ->has('connections.0.aspsp_name')
    );
});
