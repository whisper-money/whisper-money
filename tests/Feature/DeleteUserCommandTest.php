<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingConnectionStatus;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\AutomationRule;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Models\Category;
use App\Models\EncryptedMessage;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserMailLog;
use Laravel\Cashier\Subscription;

test('marks user as deleted, preserves data, and prefixes email with timestamp when confirmed', function () {
    $this->travelTo(now()->setDate(2026, 4, 22)->setTime(10, 51, 24));

    $user = User::factory()->onboarded()->create([
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldNotReceive('revokeSession');
    app()->instance(BankingProviderInterface::class, $mockProvider);

    // Create associated data
    EncryptedMessage::query()->create([
        'user_id' => $user->id,
        'encrypted_content' => 'test-content',
        'iv' => 'test-iv',
    ]);
    Transaction::factory()->count(3)->create(['user_id' => $user->id]);
    $account = Account::factory()->create(['user_id' => $user->id]);
    AccountBalance::factory()->count(2)->create(['account_id' => $account->id]);
    Account::factory()->create(['user_id' => $user->id]);
    Category::factory()->count(2)->create(['user_id' => $user->id]);
    AutomationRule::factory()->count(1)->create(['user_id' => $user->id]);
    Label::factory()->count(2)->create(['user_id' => $user->id]);
    UserMailLog::factory()->count(1)->create(['user_id' => $user->id]);
    Bank::factory()->count(2)->create(['user_id' => $user->id]);

    // Confirm deletion
    $this->artisan('user:delete', ['email' => 'test@example.com'])
        ->expectsConfirmation("Are you sure you want to mark user 'Test User' (test@example.com) as deleted? Their data will be preserved.", 'yes')
        ->expectsOutput("User 'test@example.com' has been marked as deleted. Their data remains in the database.")
        ->assertSuccessful();

    $deletedUser = User::withTrashed()->find($user->id);

    expect(User::query()->where('email', 'test@example.com')->exists())->toBeFalse();
    expect($deletedUser?->deleted_at)->not->toBeNull();
    expect($deletedUser?->email)->toBe('20260422105124_test@example.com');

    expect(EncryptedMessage::query()->where('user_id', $user->id)->exists())->toBeTrue();
    expect(Transaction::query()->where('user_id', $user->id)->exists())->toBeTrue();
    expect(Account::query()->where('user_id', $user->id)->exists())->toBeTrue();
    expect(AccountBalance::query()->where('account_id', $account->id)->exists())->toBeTrue();
    expect(Category::query()->where('user_id', $user->id)->exists())->toBeTrue();
    expect(AutomationRule::query()->where('user_id', $user->id)->exists())->toBeTrue();
    expect(Label::query()->where('user_id', $user->id)->exists())->toBeTrue();
    expect(UserMailLog::query()->where('user_id', $user->id)->exists())->toBeTrue();
    expect(Bank::query()->where('user_id', $user->id)->exists())->toBeTrue();
});

test('shows error when user not found', function () {
    $this->artisan('user:delete', ['email' => 'nonexistent@example.com'])
        ->expectsOutput("User with email 'nonexistent@example.com' not found.")
        ->assertFailed();
});

test('cancels deletion when not confirmed', function () {
    $user = User::factory()->onboarded()->create([
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    $this->artisan('user:delete', ['email' => 'test@example.com'])
        ->expectsConfirmation("Are you sure you want to mark user 'Test User' (test@example.com) as deleted? Their data will be preserved.", 'no')
        ->expectsOutput('Deletion cancelled.')
        ->assertSuccessful();

    // Verify user still exists
    expect(User::query()->where('email', 'test@example.com')->exists())->toBeTrue();
});

test('marks user as deleted without associated data', function () {
    $this->travelTo(now()->setDate(2026, 4, 22)->setTime(10, 51, 24));

    $user = User::factory()->onboarded()->create([
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldNotReceive('revokeSession');
    app()->instance(BankingProviderInterface::class, $mockProvider);

    $this->artisan('user:delete', ['email' => 'test@example.com'])
        ->expectsConfirmation("Are you sure you want to mark user 'Test User' (test@example.com) as deleted? Their data will be preserved.", 'yes')
        ->expectsOutput("User 'test@example.com' has been marked as deleted. Their data remains in the database.")
        ->assertSuccessful();

    $deletedUser = User::withTrashed()->find($user->id);

    expect(User::query()->where('email', 'test@example.com')->exists())->toBeFalse();
    expect($deletedUser?->deleted_at)->not->toBeNull();
    expect($deletedUser?->email)->toBe('20260422105124_test@example.com');
});

test('does not delete other users data', function () {
    $this->travelTo(now()->setDate(2026, 4, 22)->setTime(10, 51, 24));

    $userToDelete = User::factory()->onboarded()->create([
        'email' => 'delete@example.com',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldNotReceive('revokeSession');
    app()->instance(BankingProviderInterface::class, $mockProvider);
    $otherUser = User::factory()->onboarded()->create([
        'email' => 'keep@example.com',
    ]);

    // Create data for both users
    Account::factory()->create(['user_id' => $userToDelete->id]);
    Account::factory()->create(['user_id' => $otherUser->id]);

    $this->artisan('user:delete', ['email' => 'delete@example.com'])
        ->expectsConfirmation("Are you sure you want to mark user '{$userToDelete->name}' (delete@example.com) as deleted? Their data will be preserved.", 'yes')
        ->assertSuccessful();

    $deletedUser = User::withTrashed()->find($userToDelete->id);

    expect(User::query()->where('email', 'delete@example.com')->exists())->toBeFalse();
    expect($deletedUser?->deleted_at)->not->toBeNull();
    expect($deletedUser?->email)->toBe('20260422105124_delete@example.com');
    expect(User::query()->where('email', 'keep@example.com')->exists())->toBeTrue();

    // Verify other user's data is intact
    expect(Account::query()->where('user_id', $otherUser->id)->exists())->toBeTrue();
});

test('cancels active subscription before deleting user when confirmed', function () {
    $this->travelTo(now()->setDate(2026, 4, 22)->setTime(10, 51, 24));

    $user = User::factory()->onboarded()->create([
        'email' => 'subscribed@example.com',
        'name' => 'Subscribed User',
    ]);

    $subscription = Subscription::query()->create([
        'user_id' => $user->id,
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
        'quantity' => 1,
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldNotReceive('revokeSession');
    app()->instance(BankingProviderInterface::class, $mockProvider);

    $this->artisan('user:delete', ['email' => 'subscribed@example.com'])
        ->expectsConfirmation("Are you sure you want to mark user 'Subscribed User' (subscribed@example.com) as deleted? Their data will be preserved.", 'yes')
        ->expectsConfirmation("User 'subscribed@example.com' has an active Stripe subscription. Cancel it before deleting the user?", 'yes')
        ->expectsOutput("Cancelled active Stripe subscription for 'subscribed@example.com'.")
        ->expectsOutput("User 'subscribed@example.com' has been marked as deleted. Their data remains in the database.")
        ->assertSuccessful();

    expect($subscription->fresh()->stripe_status)->toBe('canceled');
    expect($subscription->fresh()->ends_at)->not->toBeNull();
    expect(User::withTrashed()->find($user->id)?->deleted_at)->not->toBeNull();
});

test('cancels deletion when subscription cancellation is not confirmed', function () {
    $user = User::factory()->onboarded()->create([
        'email' => 'subscribed@example.com',
        'name' => 'Subscribed User',
    ]);

    Subscription::query()->create([
        'user_id' => $user->id,
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
        'quantity' => 1,
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldNotReceive('revokeSession');
    app()->instance(BankingProviderInterface::class, $mockProvider);

    $this->artisan('user:delete', ['email' => 'subscribed@example.com'])
        ->expectsConfirmation("Are you sure you want to mark user 'Subscribed User' (subscribed@example.com) as deleted? Their data will be preserved.", 'yes')
        ->expectsConfirmation("User 'subscribed@example.com' has an active Stripe subscription. Cancel it before deleting the user?", 'no')
        ->expectsOutput('Deletion cancelled.')
        ->assertSuccessful();

    expect(User::query()->where('email', 'subscribed@example.com')->exists())->toBeTrue();
    expect(Subscription::query()->where('user_id', $user->id)->first()?->stripe_status)->toBe('active');
});

test('revokes enable banking connections before deleting user when confirmed', function () {
    $this->travelTo(now()->setDate(2026, 4, 22)->setTime(10, 51, 24));

    $user = User::factory()->onboarded()->create([
        'email' => 'banking@example.com',
        'name' => 'Banking User',
    ]);
    $connection = BankingConnection::factory()->for($user)->create();
    $account = Account::factory()->for($user)->create([
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('revokeSession')->once()->with($connection->session_id);
    app()->instance(BankingProviderInterface::class, $mockProvider);

    $this->artisan('user:delete', ['email' => 'banking@example.com'])
        ->expectsConfirmation("Are you sure you want to mark user 'Banking User' (banking@example.com) as deleted? Their data will be preserved.", 'yes')
        ->expectsConfirmation("User 'banking@example.com' has 1 Enable Banking connection(s). Revoke them and keep linked accounts as manual accounts?", 'yes')
        ->expectsOutput("Revoked 1 Enable Banking connection(s) for 'banking@example.com'.")
        ->expectsOutput("User 'banking@example.com' has been marked as deleted. Their data remains in the database.")
        ->assertSuccessful();

    expect($connection->fresh()->status)->toBe(BankingConnectionStatus::Revoked);
    expect($connection->fresh()->trashed())->toBeTrue();
    expect($account->fresh()->banking_connection_id)->toBeNull();
    expect($account->fresh()->external_account_id)->toBeNull();
    expect(User::withTrashed()->find($user->id)?->deleted_at)->not->toBeNull();
});

test('cancels deletion when enable banking revocation is not confirmed', function () {
    $user = User::factory()->onboarded()->create([
        'email' => 'banking@example.com',
        'name' => 'Banking User',
    ]);
    BankingConnection::factory()->for($user)->create();

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldNotReceive('revokeSession');
    app()->instance(BankingProviderInterface::class, $mockProvider);

    $this->artisan('user:delete', ['email' => 'banking@example.com'])
        ->expectsConfirmation("Are you sure you want to mark user 'Banking User' (banking@example.com) as deleted? Their data will be preserved.", 'yes')
        ->expectsConfirmation("User 'banking@example.com' has 1 Enable Banking connection(s). Revoke them and keep linked accounts as manual accounts?", 'no')
        ->expectsOutput('Deletion cancelled.')
        ->assertSuccessful();

    expect(User::query()->where('email', 'banking@example.com')->exists())->toBeTrue();
    expect(BankingConnection::query()->where('user_id', $user->id)->first()?->trashed())->toBeFalse();
});
