<?php

use App\Actions\CreateDefaultCategories;
use App\Models\Account;
use App\Models\Category;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;

it('provisions a personal space when a user is created', function () {
    $user = User::factory()->create();

    expect($user->personalSpace)->not->toBeNull()
        ->and($user->personalSpace->personal)->toBeTrue()
        ->and($user->current_space_id)->toBe($user->personalSpace->id)
        ->and($user->activeSpace()->id)->toBe($user->personalSpace->id);
});

it('stamps owned rows with the owner\'s current space by default', function () {
    $user = User::factory()->create();

    $account = Account::factory()->for($user)->create();

    expect($account->space_id)->toBe($user->current_space_id);
});

it('inherits a transaction\'s space from its account, not the acting user', function () {
    $owner = User::factory()->create();
    $account = Account::factory()->for($owner)->create();

    // A transaction created with a different acting user still lands in the
    // account's space (the account is the tenant anchor).
    $other = User::factory()->create();
    $transaction = Transaction::factory()->for($account)->create(['user_id' => $other->id]);

    expect($transaction->space_id)->toBe($account->space_id);
});

it('seeds default categories into the given space', function () {
    $user = User::factory()->create();

    app(CreateDefaultCategories::class)->handle($user);

    expect(Category::where('space_id', $user->current_space_id)->count())->toBeGreaterThan(0)
        ->and(Category::whereNull('space_id')->count())->toBe(0);
});

it('resolves active space back to personal when the pointer is stale', function () {
    $user = User::factory()->create();
    $foreign = Space::factory()->create();

    // Point the user at a space they cannot access.
    $user->forceFill(['current_space_id' => $foreign->id])->saveQuietly();
    $user->refresh();

    expect($user->activeSpace()->personal)->toBeTrue()
        ->and($user->fresh()->current_space_id)->toBe($user->personalSpace->id);
});

it('backfills legacy rows that predate spaces', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    // Simulate a pre-migration state: no space, dangling pointers.
    Account::query()->where('id', $account->id)->update(['space_id' => null]);
    User::query()->where('id', $user->id)->update(['current_space_id' => null]);
    Space::query()->where('owner_id', $user->id)->delete();

    $this->artisan('spaces:backfill')->assertSuccessful();

    $user->refresh();
    expect($user->current_space_id)->not->toBeNull()
        ->and($account->fresh()->space_id)->toBe($user->current_space_id);
});

it('backfills soft-deleted users and their rows', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    // Simulate a pre-migration, soft-deleted user with dangling data.
    Account::query()->where('id', $account->id)->update(['space_id' => null]);
    User::query()->where('id', $user->id)->update(['current_space_id' => null]);
    Space::query()->where('owner_id', $user->id)->delete();
    $user->delete();

    $this->artisan('spaces:backfill')->assertSuccessful();

    $trashed = User::withTrashed()->find($user->id);
    expect($trashed->trashed())->toBeTrue()
        ->and($trashed->current_space_id)->not->toBeNull()
        ->and($account->fresh()->space_id)->toBe($trashed->current_space_id);
});
