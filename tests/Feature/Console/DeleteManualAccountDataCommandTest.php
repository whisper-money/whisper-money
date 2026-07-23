<?php

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Transaction;
use App\Models\User;

test('deletes transactions and balances of non-connected accounts only', function () {
    $user = User::factory()->onboarded()->create(['email' => 'test@example.com']);

    $manual = Account::factory()->for($user)->create();
    $connected = Account::factory()->connected()->for($user)->create();

    Transaction::factory()->count(3)->create(['user_id' => $user->id, 'account_id' => $manual->id]);
    AccountBalance::factory()->count(2)->create(['account_id' => $manual->id]);
    Transaction::factory()->count(4)->create(['user_id' => $user->id, 'account_id' => $connected->id]);
    AccountBalance::factory()->count(5)->create(['account_id' => $connected->id]);

    $this->artisan('user:delete-manual-account-data', ['email' => 'test@example.com'])
        ->expectsConfirmation("Delete 3 transaction(s) and 2 balance(s) across 1 non-connected account(s) of 'test@example.com'?", 'yes')
        ->assertSuccessful();

    expect(Transaction::withTrashed()->where('account_id', $manual->id)->exists())->toBeFalse();
    expect(AccountBalance::query()->where('account_id', $manual->id)->exists())->toBeFalse();
    expect(Transaction::query()->where('account_id', $connected->id)->count())->toBe(4);
    expect(AccountBalance::query()->where('account_id', $connected->id)->count())->toBe(5);
});

test('does not touch other users data', function () {
    $user = User::factory()->onboarded()->create(['email' => 'test@example.com']);
    $other = User::factory()->onboarded()->create(['email' => 'keep@example.com']);

    $otherAccount = Account::factory()->for($other)->create();
    Transaction::factory()->count(2)->create(['user_id' => $other->id, 'account_id' => $otherAccount->id]);
    AccountBalance::factory()->count(2)->create(['account_id' => $otherAccount->id]);

    $this->artisan('user:delete-manual-account-data', ['email' => 'test@example.com'])
        ->expectsOutput("User 'test@example.com' has no non-connected accounts.")
        ->assertSuccessful();

    expect(Transaction::query()->where('account_id', $otherAccount->id)->count())->toBe(2);
    expect(AccountBalance::query()->where('account_id', $otherAccount->id)->count())->toBe(2);
});

test('cancels when not confirmed', function () {
    $user = User::factory()->onboarded()->create(['email' => 'test@example.com']);
    $manual = Account::factory()->for($user)->create();
    Transaction::factory()->count(3)->create(['user_id' => $user->id, 'account_id' => $manual->id]);

    $this->artisan('user:delete-manual-account-data', ['email' => 'test@example.com'])
        ->expectsConfirmation("Delete 3 transaction(s) and 0 balance(s) across 1 non-connected account(s) of 'test@example.com'?", 'no')
        ->expectsOutput('Deletion cancelled.')
        ->assertSuccessful();

    expect(Transaction::query()->where('account_id', $manual->id)->count())->toBe(3);
});

test('shows error when user not found', function () {
    $this->artisan('user:delete-manual-account-data', ['email' => 'nobody@example.com'])
        ->expectsOutput("User with email 'nobody@example.com' not found.")
        ->assertFailed();
});
