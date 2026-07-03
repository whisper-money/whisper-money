<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\artisan;

test('it soft-deletes users with encrypted data who did not sign in within the grace window', function () {
    $inactive = User::factory()->create(['last_active_at' => now()->subDays(30)]);
    Transaction::factory()->for($inactive)->create();

    $neverActive = User::factory()->create(['last_active_at' => null]);
    Account::factory()->for($neverActive)->create(['name_iv' => 'abcdef0123456789']);

    $recentlyActive = User::factory()->create(['last_active_at' => now()->subDay()]);
    Transaction::factory()->for($recentlyActive)->create();

    $clean = User::factory()->create(['last_active_at' => now()->subDays(30)]);
    Transaction::factory()->for($clean)->plaintext()->create();

    artisan('encryption:delete-accounts', ['--force' => true])->assertSuccessful();

    // The default soft-delete scope hides trashed users, so a missing row means it was deleted.
    expect(User::whereKey($inactive->id)->exists())->toBeFalse();
    expect(User::whereKey($neverActive->id)->exists())->toBeFalse();
    expect(User::whereKey($recentlyActive->id)->exists())->toBeTrue();
    expect(User::whereKey($clean->id)->exists())->toBeTrue();
});

test('it never deletes the demo account', function () {
    $demo = User::factory()->create([
        'email' => config('app.demo.email'),
        'last_active_at' => now()->subDays(30),
    ]);
    Transaction::factory()->for($demo)->create();

    artisan('encryption:delete-accounts', ['--force' => true])->assertSuccessful();

    expect(User::whereKey($demo->id)->exists())->toBeTrue();
});

test('dry run deletes nothing', function () {
    $user = User::factory()->create(['last_active_at' => null]);
    Transaction::factory()->for($user)->create();

    artisan('encryption:delete-accounts', ['--dry-run' => true])->assertSuccessful();

    expect(User::whereKey($user->id)->exists())->toBeTrue();
});
