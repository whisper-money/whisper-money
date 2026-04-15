<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingConnectionStatus;
use App\Mail\EnableBankingConnectionsCancelledEmail;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\artisan;

test('revokes old enable banking connections for free users and keeps accounts manual', function () {
    config(['subscriptions.enabled' => true]);
    Mail::fake();

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

    Mail::assertQueued(EnableBankingConnectionsCancelledEmail::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email) && $mail->removedConnectionsCount === 1;
    });
});

test('skips enable banking connections created less than six hours ago', function () {
    config(['subscriptions.enabled' => true]);
    Mail::fake();

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
    Mail::assertNothingOutgoing();
});

test('skips subscribed users', function () {
    config(['subscriptions.enabled' => true]);
    Mail::fake();

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
    Mail::assertNothingOutgoing();
});

test('skips non enable banking providers', function () {
    config(['subscriptions.enabled' => true]);
    Mail::fake();

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
    Mail::assertNothingOutgoing();
});

test('continues disconnect when enable banking revoke fails', function () {
    config(['subscriptions.enabled' => true]);
    Mail::fake();

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

    Mail::assertQueued(EnableBankingConnectionsCancelledEmail::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email) && $mail->removedConnectionsCount === 1;
    });
});

test('sends one email per user when multiple connections are removed', function () {
    config(['subscriptions.enabled' => true]);
    Mail::fake();

    $user = User::factory()->create();
    $firstConnection = BankingConnection::factory()->for($user)->create([
        'created_at' => now()->subHours(7),
    ]);
    $secondConnection = BankingConnection::factory()->for($user)->create([
        'created_at' => now()->subHours(8),
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('revokeSession')->once()->with($firstConnection->session_id);
    $mockProvider->shouldReceive('revokeSession')->once()->with($secondConnection->session_id);
    app()->instance(BankingProviderInterface::class, $mockProvider);

    artisan('banking:cancel-free-enablebanking')
        ->expectsOutputToContain('Revoked 2 Enable Banking connection(s). Skipped paid users: 0.')
        ->assertSuccessful();

    Mail::assertQueued(EnableBankingConnectionsCancelledEmail::class, 1);
    Mail::assertQueued(EnableBankingConnectionsCancelledEmail::class, function ($mail) use ($user) {
        return $mail->hasTo($user->email) && $mail->removedConnectionsCount === 2;
    });
});
