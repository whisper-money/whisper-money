<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingConnectionStatus;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;

use function Pest\Laravel\artisan;

test('revokes old enable banking connections for free users and keeps accounts manual', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->create();
    $connection = BankingConnection::factory()->for($user)->create([
        'created_at' => now()->subHours(7),
    ]);
    $account = Account::factory()->for($user)->create([
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('revokeSession')->once()->with($connection->session_id);
    app()->instance(BankingProviderInterface::class, $mockProvider);

    artisan('banking:cancel-free-enablebanking')
        ->expectsOutputToContain('Revoked 1 Enable Banking connection(s). Skipped paid users: 0.')
        ->assertSuccessful();

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Revoked);
    expect($connection->trashed())->toBeTrue();

    $account->refresh();
    expect($account->banking_connection_id)->toBeNull();
    expect($account->external_account_id)->toBeNull();
    expect($account->trashed())->toBeFalse();
});

test('skips enable banking connections created less than six hours ago', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->create();
    $connection = BankingConnection::factory()->for($user)->create([
        'created_at' => now()->subHours(5),
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldNotReceive('revokeSession');
    app()->instance(BankingProviderInterface::class, $mockProvider);

    artisan('banking:cancel-free-enablebanking')
        ->expectsOutputToContain('No eligible Enable Banking connections found for free users.')
        ->assertSuccessful();

    expect($connection->fresh()->trashed())->toBeFalse();
    expect($connection->fresh()->status)->not->toBe(BankingConnectionStatus::Revoked);
});

test('skips subscribed users', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->create();
    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
    ]);

    $connection = BankingConnection::factory()->for($user)->create([
        'created_at' => now()->subHours(7),
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldNotReceive('revokeSession');
    app()->instance(BankingProviderInterface::class, $mockProvider);

    artisan('banking:cancel-free-enablebanking')
        ->expectsOutputToContain('Revoked 0 Enable Banking connection(s). Skipped paid users: 1.')
        ->assertSuccessful();

    expect($connection->fresh()->trashed())->toBeFalse();
});

test('skips non enable banking providers', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->create();
    $connection = BankingConnection::factory()->for($user)->indexaCapital()->create([
        'created_at' => now()->subHours(7),
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldNotReceive('revokeSession');
    app()->instance(BankingProviderInterface::class, $mockProvider);

    artisan('banking:cancel-free-enablebanking')
        ->expectsOutputToContain('No eligible Enable Banking connections found for free users.')
        ->assertSuccessful();

    expect($connection->fresh()->trashed())->toBeFalse();
});

test('continues disconnect when enable banking revoke fails', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->create();
    $connection = BankingConnection::factory()->for($user)->create([
        'created_at' => now()->subHours(7),
    ]);
    $account = Account::factory()->for($user)->create([
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-456',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('revokeSession')->once()->andThrow(new RuntimeException('API unavailable'));
    app()->instance(BankingProviderInterface::class, $mockProvider);

    artisan('banking:cancel-free-enablebanking')->assertSuccessful();

    expect($connection->fresh()->trashed())->toBeTrue();

    $account->refresh();
    expect($account->banking_connection_id)->toBeNull();
    expect($account->external_account_id)->toBeNull();
});
