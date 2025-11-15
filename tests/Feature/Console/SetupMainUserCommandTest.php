<?php

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\artisan;

it('imports data for user by uuid', function () {
    $user = User::factory()->create();

    artisan('user:setup-main', ['user' => $user->id])
        ->assertSuccessful();
});

it('imports data for user by email', function () {
    $user = User::factory()->create(['email' => 'test@example.com']);

    artisan('user:setup-main', ['user' => 'test@example.com'])
        ->assertSuccessful();
});

it('asks for user identifier when not provided as argument', function () {
    $user = User::factory()->create(['email' => 'test@example.com']);

    artisan('user:setup-main')
        ->expectsQuestion('Enter user UUID or email', 'test@example.com')
        ->assertSuccessful();
});

it('fails when user is not found by uuid', function () {
    artisan('user:setup-main', ['user' => '00000000-0000-0000-0000-000000000000'])
        ->expectsOutput('User not found: 00000000-0000-0000-0000-000000000000')
        ->assertFailed();
});

it('fails when user is not found by email', function () {
    artisan('user:setup-main', ['user' => 'nonexistent@example.com'])
        ->expectsOutput('User not found: nonexistent@example.com')
        ->assertFailed();
});

it('imports categories from json file', function () {
    $user = User::factory()->create();
    $localPath = base_path('.local');
    File::ensureDirectoryExists($localPath);

    $categoriesData = [
        [
            'name' => 'Food',
            'icon' => 'Utensils',
            'color' => 'red',
            'created_at' => '2025-11-14T14:20:48.000000Z',
            'updated_at' => '2025-11-14T14:20:48.000000Z',
        ],
        [
            'name' => 'Transportation',
            'icon' => 'Bus',
            'color' => 'amber',
            'created_at' => '2025-11-14T14:20:48.000000Z',
            'updated_at' => '2025-11-14T14:20:48.000000Z',
        ],
    ];

    File::put("{$localPath}/categories.json", json_encode($categoriesData));

    artisan('user:setup-main', ['user' => $user->id])
        ->assertSuccessful();

    $user->refresh();

    expect($user->categories)->toHaveCount(2)
        ->and($user->categories->pluck('name')->toArray())->toContain('Food', 'Transportation');

    File::delete("{$localPath}/categories.json");
});

it('imports automation rules from json file', function () {
    $user = User::factory()->create();
    $localPath = base_path('.local');
    File::ensureDirectoryExists($localPath);

    $categoriesData = [
        [
            'name' => 'Groceries',
            'icon' => 'ShoppingBasket',
            'color' => 'red',
            'created_at' => '2025-11-14T14:20:48.000000Z',
            'updated_at' => '2025-11-14T14:20:48.000000Z',
        ],
    ];

    $rulesData = [
        [
            'title' => 'Groceries Rule',
            'priority' => 10,
            'rules_json' => '{"in":["MERCADONA",{"var":"description"}]}',
            'action_note' => null,
            'action_note_iv' => null,
            'created_at' => '2025-11-14T09:10:05.000000Z',
            'updated_at' => '2025-11-14T14:26:10.000000Z',
            'category' => [
                'name' => 'Groceries',
            ],
        ],
    ];

    File::put("{$localPath}/categories.json", json_encode($categoriesData));
    File::put("{$localPath}/automated_rules.json", json_encode($rulesData));

    artisan('user:setup-main', ['user' => $user->id])
        ->assertSuccessful();

    $user->refresh();

    expect($user->automationRules)->toHaveCount(1)
        ->and($user->automationRules->first()->title)->toBe('Groceries Rule')
        ->and($user->automationRules->first()->category->name)->toBe('Groceries');

    File::delete("{$localPath}/categories.json");
    File::delete("{$localPath}/automated_rules.json");
});

it('skips automation rules when category is not found', function () {
    $user = User::factory()->create();
    $localPath = base_path('.local');
    File::ensureDirectoryExists($localPath);

    $rulesData = [
        [
            'title' => 'Orphan Rule',
            'priority' => 10,
            'rules_json' => '{"in":["TEST",{"var":"description"}]}',
            'action_note' => null,
            'action_note_iv' => null,
            'created_at' => '2025-11-14T09:10:05.000000Z',
            'updated_at' => '2025-11-14T14:26:10.000000Z',
            'category' => [
                'name' => 'Non Existent Category',
            ],
        ],
    ];

    File::put("{$localPath}/automated_rules.json", json_encode($rulesData));

    artisan('user:setup-main', ['user' => $user->id])
        ->assertSuccessful();

    $user->refresh();

    expect($user->automationRules)->toHaveCount(0);

    File::delete("{$localPath}/automated_rules.json");
});

it('handles missing local directory gracefully', function () {
    $user = User::factory()->create();
    $localPath = base_path('.local');

    if (File::isDirectory($localPath)) {
        File::deleteDirectory($localPath);
    }

    artisan('user:setup-main', ['user' => $user->id])
        ->assertSuccessful();

    File::ensureDirectoryExists($localPath);
});

it('imports data for existing user with existing categories', function () {
    $user = User::factory()->create();
    Category::factory()->create(['user_id' => $user->id, 'name' => 'Existing Category']);

    $localPath = base_path('.local');
    File::ensureDirectoryExists($localPath);

    $categoriesData = [
        [
            'name' => 'New Category',
            'icon' => 'Star',
            'color' => 'blue',
            'created_at' => '2025-11-14T14:20:48.000000Z',
            'updated_at' => '2025-11-14T14:20:48.000000Z',
        ],
    ];

    File::put("{$localPath}/categories.json", json_encode($categoriesData));

    artisan('user:setup-main', ['user' => $user->id])
        ->assertSuccessful();

    $user->refresh();

    expect($user->categories)->toHaveCount(2)
        ->and($user->categories->pluck('name')->toArray())->toContain('Existing Category', 'New Category');

    File::delete("{$localPath}/categories.json");
});
