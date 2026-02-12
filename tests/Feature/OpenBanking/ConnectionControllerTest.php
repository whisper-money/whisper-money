<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingConnectionStatus;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\BankingConnection;
use App\Models\Transaction;
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

test('users can view their connections page', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('open-banking');

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
    Feature::for($user)->activate('open-banking');

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
    Feature::for($user)->activate('open-banking');

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
    Feature::for($user)->activate('open-banking');

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
    Feature::for($user)->activate('open-banking');

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
    Feature::for($user)->activate('open-banking');

    $connection = BankingConnection::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($user)->delete("/settings/connections/{$connection->id}", [
        'delete_accounts' => false,
    ]);

    $response->assertForbidden();
});

test('users can trigger manual sync on active connection', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('open-banking');

    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'status' => BankingConnectionStatus::Active,
    ]);

    $response = $this->actingAs($user)->post("/settings/connections/{$connection->id}/sync");

    $response->assertRedirect();
    $response->assertSessionHas('success');
});

test('users cannot sync expired connection', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('open-banking');

    $connection = BankingConnection::factory()->expired()->create([
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->post("/settings/connections/{$connection->id}/sync");

    $response->assertRedirect();
    $response->assertSessionHas('error');
});
