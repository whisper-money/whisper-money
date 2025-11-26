<?php

use App\Models\Category;
use App\Models\User;

test('command resets categories for a user by ID', function () {
    $user = User::factory()->create();
    Category::factory()->count(5)->create(['user_id' => $user->id]);

    expect($user->categories()->count())->toBe(5);

    $this->artisan('categories:reset', ['user' => $user->id])
        ->expectsOutput("Resetting categories for user: {$user->name} ({$user->email})")
        ->expectsOutput('Deleted 5 existing categories.')
        ->expectsOutput('Created 63 default categories.')
        ->expectsOutput('✓ Categories reset successfully!')
        ->assertSuccessful();

    expect($user->fresh()->categories()->count())->toBe(63);

    $categoryNames = $user->categories->pluck('name')->toArray();
    expect($categoryNames)->toContain('Food', 'Transportation', 'Salary', 'Insurance');
});

test('command resets categories for a user by email', function () {
    $user = User::factory()->create(['email' => 'test@example.com']);
    Category::factory()->count(3)->create(['user_id' => $user->id]);

    expect($user->categories()->count())->toBe(3);

    $this->artisan('categories:reset', ['user' => 'test@example.com'])
        ->expectsOutput("Resetting categories for user: {$user->name} (test@example.com)")
        ->expectsOutput('Deleted 3 existing categories.')
        ->expectsOutput('Created 63 default categories.')
        ->expectsOutput('✓ Categories reset successfully!')
        ->assertSuccessful();

    expect($user->fresh()->categories()->count())->toBe(63);
});

test('command works when user has no existing categories', function () {
    $user = User::factory()->create();

    expect($user->categories()->count())->toBe(0);

    $this->artisan('categories:reset', ['user' => $user->id])
        ->expectsOutput("Resetting categories for user: {$user->name} ({$user->email})")
        ->expectsOutput('No existing categories found.')
        ->expectsOutput('Created 63 default categories.')
        ->expectsOutput('✓ Categories reset successfully!')
        ->assertSuccessful();

    expect($user->fresh()->categories()->count())->toBe(63);
});

test('command fails when user is not found by ID', function () {
    $this->artisan('categories:reset', ['user' => 99999])
        ->expectsOutput('User not found: 99999')
        ->assertFailed();
});

test('command fails when user is not found by email', function () {
    $this->artisan('categories:reset', ['user' => 'nonexistent@example.com'])
        ->expectsOutput('User not found: nonexistent@example.com')
        ->assertFailed();
});
