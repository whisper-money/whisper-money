<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingConnectionStatus;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;

use function Pest\Laravel\artisan;

test('disconnects multiple connections by comma-separated ids and revokes sessions', function () {
    $user = User::factory()->create();
    $first = BankingConnection::factory()->for($user)->create();
    $second = BankingConnection::factory()->for($user)->create();
    $account = Account::factory()->for($user)->create([
        'banking_connection_id' => $first->id,
        'external_account_id' => 'ext-123',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('revokeSession')->once()->with($first->session_id);
    $mockProvider->shouldReceive('revokeSession')->once()->with($second->session_id);
    app()->instance(BankingProviderInterface::class, $mockProvider);

    artisan('banking:disconnect', ['ids' => "{$first->id}, {$second->id}"])
        ->expectsOutputToContain('Disconnected 2 of 2 connection(s).')
        ->assertSuccessful();

    expect($first->fresh()->trashed())->toBeTrue();
    expect($first->fresh()->status)->toBe(BankingConnectionStatus::Revoked);
    expect($second->fresh()->trashed())->toBeTrue();

    $account->refresh();
    expect($account->banking_connection_id)->toBeNull();
    expect($account->external_account_id)->toBeNull();
    expect($account->trashed())->toBeFalse();
});

test('hard deletes linked accounts with delete-accounts flag', function () {
    $user = User::factory()->create();
    $connection = BankingConnection::factory()->for($user)->create();
    $account = Account::factory()->for($user)->create([
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-456',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('revokeSession')->once()->with($connection->session_id);
    app()->instance(BankingProviderInterface::class, $mockProvider);

    artisan('banking:disconnect', ['ids' => $connection->id, '--delete-accounts' => true])
        ->assertSuccessful();

    expect($connection->fresh()->trashed())->toBeTrue();
    expect($account->fresh()->trashed())->toBeTrue();
});

test('warns about missing ids and fails', function () {
    $user = User::factory()->create();
    $connection = BankingConnection::factory()->for($user)->create();

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('revokeSession')->once()->with($connection->session_id);
    app()->instance(BankingProviderInterface::class, $mockProvider);

    artisan('banking:disconnect', ['ids' => "{$connection->id},missing-id"])
        ->expectsOutputToContain('Connection not found: missing-id')
        ->assertFailed();

    expect($connection->fresh()->trashed())->toBeTrue();
});

test('fails when no matching connections found', function () {
    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldNotReceive('revokeSession');
    app()->instance(BankingProviderInterface::class, $mockProvider);

    artisan('banking:disconnect', ['ids' => 'nope-1,nope-2'])
        ->expectsOutputToContain('No matching banking connections found.')
        ->assertFailed();
});
