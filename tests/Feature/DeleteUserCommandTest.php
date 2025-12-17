<?php

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\AutomationRule;
use App\Models\Bank;
use App\Models\Category;
use App\Models\EncryptedMessage;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Subscription;

uses(RefreshDatabase::class);

test('deletes user and all associated data when confirmed', function () {
    $user = User::factory()->onboarded()->create([
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

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
        ->expectsConfirmation("Are you sure you want to delete user 'Test User' (test@example.com) and all their data?", 'yes')
        ->expectsOutput("User 'test@example.com' and all associated data have been deleted successfully.")
        ->assertSuccessful();

    // Verify user is deleted
    expect(User::query()->where('email', 'test@example.com')->exists())->toBeFalse();

    // Verify all associated data is deleted
    expect(EncryptedMessage::query()->where('user_id', $user->id)->exists())->toBeFalse();
    expect(Transaction::query()->where('user_id', $user->id)->exists())->toBeFalse();
    expect(Account::query()->where('user_id', $user->id)->exists())->toBeFalse();
    expect(AccountBalance::query()->where('account_id', $account->id)->exists())->toBeFalse();
    expect(Category::query()->where('user_id', $user->id)->exists())->toBeFalse();
    expect(AutomationRule::query()->where('user_id', $user->id)->exists())->toBeFalse();
    expect(Label::query()->where('user_id', $user->id)->exists())->toBeFalse();
    expect(UserMailLog::query()->where('user_id', $user->id)->exists())->toBeFalse();
    expect(Bank::query()->where('user_id', $user->id)->exists())->toBeFalse();
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
        ->expectsConfirmation("Are you sure you want to delete user 'Test User' (test@example.com) and all their data?", 'no')
        ->expectsOutput('Deletion cancelled.')
        ->assertSuccessful();

    // Verify user still exists
    expect(User::query()->where('email', 'test@example.com')->exists())->toBeTrue();
});

test('deletes user without associated data', function () {
    $user = User::factory()->onboarded()->create([
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    $this->artisan('user:delete', ['email' => 'test@example.com'])
        ->expectsConfirmation("Are you sure you want to delete user 'Test User' (test@example.com) and all their data?", 'yes')
        ->expectsOutput("User 'test@example.com' and all associated data have been deleted successfully.")
        ->assertSuccessful();

    expect(User::query()->where('email', 'test@example.com')->exists())->toBeFalse();
});

test('does not delete other users data', function () {
    $userToDelete = User::factory()->onboarded()->create([
        'email' => 'delete@example.com',
    ]);
    $otherUser = User::factory()->onboarded()->create([
        'email' => 'keep@example.com',
    ]);

    // Create data for both users
    Account::factory()->create(['user_id' => $userToDelete->id]);
    Account::factory()->create(['user_id' => $otherUser->id]);

    $this->artisan('user:delete', ['email' => 'delete@example.com'])
        ->expectsConfirmation("Are you sure you want to delete user '{$userToDelete->name}' (delete@example.com) and all their data?", 'yes')
        ->assertSuccessful();

    // Verify only the target user is deleted
    expect(User::query()->where('email', 'delete@example.com')->exists())->toBeFalse();
    expect(User::query()->where('email', 'keep@example.com')->exists())->toBeTrue();

    // Verify other user's data is intact
    expect(Account::query()->where('user_id', $otherUser->id)->exists())->toBeTrue();
});

test('prevents deletion of user with active subscription', function () {
    $user = User::factory()->onboarded()->create([
        'email' => 'subscribed@example.com',
        'name' => 'Subscribed User',
    ]);

    // Create an active subscription
    Subscription::query()->create([
        'user_id' => $user->id,
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
        'quantity' => 1,
    ]);

    $this->artisan('user:delete', ['email' => 'subscribed@example.com'])
        ->expectsOutput('Cannot delete user with an active subscription. Please cancel the subscription first.')
        ->assertFailed();

    // Verify user still exists
    expect(User::query()->where('email', 'subscribed@example.com')->exists())->toBeTrue();
});
