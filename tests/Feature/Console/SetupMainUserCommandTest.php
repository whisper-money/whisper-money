<?php

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use function Pest\Laravel\artisan;

/*
 * Every test reads from a throwaway directory passed through --path, so the
 * suite never writes to or deletes the developer's real .local directory.
 */
beforeEach(function () {
    $this->localPath = sys_get_temp_dir().'/setup-main-user-'.Str::uuid();
    File::ensureDirectoryExists($this->localPath);
});

afterEach(function () {
    File::deleteDirectory($this->localPath);
});

it('imports data for user by uuid', function () {
    $user = User::factory()->create();

    artisan('user:setup-main', ['user' => $user->id, '--path' => $this->localPath])
        ->assertSuccessful();
});

it('imports data for user by email', function () {
    $user = User::factory()->create(['email' => 'test@example.com']);

    artisan('user:setup-main', ['user' => 'test@example.com', '--path' => $this->localPath])
        ->assertSuccessful();
});

it('asks for user identifier when not provided as argument', function () {
    $user = User::factory()->create(['email' => 'test@example.com']);

    artisan('user:setup-main', ['--path' => $this->localPath])
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

    File::put("{$this->localPath}/categories.json", json_encode($categoriesData));

    artisan('user:setup-main', ['user' => $user->id, '--path' => $this->localPath])
        ->assertSuccessful();

    $user->refresh();

    expect($user->categories)->toHaveCount(2)
        ->and($user->categories->pluck('name')->toArray())->toContain('Food', 'Transportation');
});

it('imports automation rules from json file', function () {
    $user = User::factory()->create();

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
            'created_at' => '2025-11-14T09:10:05.000000Z',
            'updated_at' => '2025-11-14T14:26:10.000000Z',
            'category' => [
                'name' => 'Groceries',
            ],
        ],
    ];

    File::put("{$this->localPath}/categories.json", json_encode($categoriesData));
    File::put("{$this->localPath}/automated_rules.json", json_encode($rulesData));

    artisan('user:setup-main', ['user' => $user->id, '--path' => $this->localPath])
        ->assertSuccessful();

    $user->refresh();

    expect($user->automationRules)->toHaveCount(1)
        ->and($user->automationRules->first()->title)->toBe('Groceries Rule')
        ->and($user->automationRules->first()->category->name)->toBe('Groceries');
});

it('skips automation rules when category is not found', function () {
    $user = User::factory()->create();

    $rulesData = [
        [
            'title' => 'Orphan Rule',
            'priority' => 10,
            'rules_json' => '{"in":["TEST",{"var":"description"}]}',
            'action_note' => null,
            'created_at' => '2025-11-14T09:10:05.000000Z',
            'updated_at' => '2025-11-14T14:26:10.000000Z',
            'category' => [
                'name' => 'Non Existent Category',
            ],
        ],
    ];

    File::put("{$this->localPath}/automated_rules.json", json_encode($rulesData));

    artisan('user:setup-main', ['user' => $user->id, '--path' => $this->localPath])
        ->assertSuccessful();

    $user->refresh();

    expect($user->automationRules)->toHaveCount(0);
});

it('handles missing local directory gracefully', function () {
    $user = User::factory()->create();
    $missingPath = "{$this->localPath}/missing";

    artisan('user:setup-main', ['user' => $user->id, '--path' => $missingPath])
        ->expectsOutputToContain("Directory not found: {$missingPath}")
        ->assertSuccessful();
});

it('reads the .local directory of the project when no path is given', function () {
    $user = User::factory()->create();

    File::partialMock()
        ->shouldReceive('isDirectory')
        ->once()
        ->with(base_path('.local'))
        ->andReturnFalse();

    artisan('user:setup-main', ['user' => $user->id])
        ->assertSuccessful();
});

it('imports data for existing user with existing categories', function () {
    $user = User::factory()->create();
    Category::factory()->create(['user_id' => $user->id, 'name' => 'Existing Category']);

    $categoriesData = [
        [
            'name' => 'New Category',
            'icon' => 'Star',
            'color' => 'blue',
            'created_at' => '2025-11-14T14:20:48.000000Z',
            'updated_at' => '2025-11-14T14:20:48.000000Z',
        ],
    ];

    File::put("{$this->localPath}/categories.json", json_encode($categoriesData));

    artisan('user:setup-main', ['user' => $user->id, '--path' => $this->localPath])
        ->assertSuccessful();

    $user->refresh();

    expect($user->categories)->toHaveCount(2)
        ->and($user->categories->pluck('name')->toArray())->toContain('Existing Category', 'New Category');
});

it('skips a file that is not a JSON array and keeps importing the rest', function () {
    $user = User::factory()->create();

    File::put("{$this->localPath}/categories.json", '{not json');
    File::put("{$this->localPath}/automated_rules.json", '"a string"');
    File::put("{$this->localPath}/extra.json", '[]');

    artisan('user:setup-main', ['user' => $user->id, '--path' => $this->localPath])
        ->expectsOutputToContain('Failed to parse categories.json')
        ->expectsOutputToContain('Invalid data format in automated_rules.json')
        ->expectsOutputToContain('Skipping extra.json (no importer defined)')
        ->assertSuccessful();

    expect($user->automationRules()->count())->toBe(0);
});
