<?php

use App\Models\Category;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('returns all categories for authenticated user', function () {
    actingAs($this->user);

    $categories = Category::factory()
        ->count(3)
        ->create(['user_id' => $this->user->id]);

    Category::factory()
        ->count(2)
        ->create();

    $response = getJson('/api/sync/categories');

    $response->assertSuccessful();
    $response->assertJsonCount(3, 'data');
});

it('returns only categories updated after specified timestamp', function () {
    actingAs($this->user);

    $oldCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'updated_at' => now()->subDays(2),
    ]);

    $newCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'updated_at' => now(),
    ]);

    $response = getJson('/api/sync/categories?since='.now()->subDay()->toIso8601String());

    $response->assertSuccessful();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $newCategory->id);
});

it('requires authentication', function () {
    $response = getJson('/api/sync/categories');

    $response->assertUnauthorized();
});
