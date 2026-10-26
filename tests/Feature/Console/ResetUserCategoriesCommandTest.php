<?php

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\User;

test('command resets the categories of a user', function (string $identifiedBy, int $existing, string $deletedLine) {
    $user = User::factory()->create(['email' => 'test@example.com']);
    Category::factory()->count($existing)->create(['user_id' => $user->id]);

    expect($user->categories()->count())->toBe($existing);

    $this->artisan('categories:reset', ['user' => $user->{$identifiedBy}])
        ->expectsOutput("Resetting categories for user: {$user->name} (test@example.com)")
        ->expectsOutput($deletedLine)
        ->expectsOutput('Created 64 default categories.')
        ->expectsOutput('✓ Categories reset successfully!')
        ->assertSuccessful();

    expect($user->fresh()->categories()->count())->toBe(64);

    $categoryNames = $user->categories->pluck('name')->toArray();
    expect($categoryNames)->toContain('Food', 'Transportation', 'Salary', 'Insurance');

    $user->refresh();

    expect($user->categories()->where('name', 'Investments')->first())
        ->type->toBe(CategoryType::Investment)
        ->cashflow_direction->toBe(CategoryCashflowDirection::Outflow);
    expect($user->categories()->where('name', 'From account of relatives')->first())
        ->type->toBe(CategoryType::Transfer)
        ->cashflow_direction->toBe(CategoryCashflowDirection::Inflow);
})->with([
    'found by id' => ['id', 5, 'Deleted 5 existing categories.'],
    'found by email' => ['email', 3, 'Deleted 3 existing categories.'],
    'with no categories yet' => ['id', 0, 'No existing categories found.'],
]);

test('command fails when the user is not found', function (int|string $identifier) {
    $this->artisan('categories:reset', ['user' => $identifier])
        ->expectsOutput("User not found: {$identifier}")
        ->assertFailed();
})->with([
    'by id' => 99999,
    'by email' => 'nonexistent@example.com',
]);
