<?php

use App\Models\Category;
use App\Models\User;

test('authenticated users can view their categories', function () {
    $user = User::factory()->create();
    $categories = Category::factory()->count(3)->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get(route('categories.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/categories')
        ->has('categories', 3)
    );
});

test('authenticated users can only view their own categories', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Category::factory()->create(['user_id' => $user->id, 'name' => 'My Category']);
    Category::factory()->create(['user_id' => $otherUser->id, 'name' => 'Other Category']);

    $response = $this->actingAs($user)->get(route('categories.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/categories')
        ->has('categories', 1)
        ->where('categories.0.name', 'My Category')
    );
});

test('authenticated users can create a category', function () {
    $user = User::factory()->create();

    $categoryData = [
        'name' => 'Shopping',
        'icon' => 'ShoppingCart',
        'color' => 'blue',
    ];

    $response = $this->actingAs($user)->post(route('categories.store'), $categoryData);

    $response->assertRedirect(route('categories.index'));

    $this->assertDatabaseHas('categories', [
        'user_id' => $user->id,
        'name' => 'Shopping',
        'icon' => 'ShoppingCart',
        'color' => 'blue',
    ]);
});

test('category name is required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('categories.store'), [
        'icon' => 'ShoppingCart',
        'color' => 'blue',
    ]);

    $response->assertSessionHasErrors(['name']);
});

test('category icon is required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('categories.store'), [
        'name' => 'Shopping',
        'color' => 'blue',
    ]);

    $response->assertSessionHasErrors(['icon']);
});

test('category color is required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('categories.store'), [
        'name' => 'Shopping',
        'icon' => 'ShoppingCart',
    ]);

    $response->assertSessionHasErrors(['color']);
});

test('category color must be valid', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('categories.store'), [
        'name' => 'Shopping',
        'icon' => 'ShoppingCart',
        'color' => 'invalid-color',
    ]);

    $response->assertSessionHasErrors(['color']);
});

test('authenticated users can update their own category', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    $updateData = [
        'name' => 'Updated Name',
        'icon' => 'Home',
        'color' => 'green',
    ];

    $response = $this->actingAs($user)->patch(
        route('categories.update', $category),
        $updateData
    );

    $response->assertRedirect(route('categories.index'));

    $this->assertDatabaseHas('categories', [
        'id' => $category->id,
        'name' => 'Updated Name',
        'icon' => 'Home',
        'color' => 'green',
    ]);
});

test('users cannot update categories they do not own', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($user)->patch(
        route('categories.update', $category),
        [
            'name' => 'Updated Name',
            'icon' => 'Home',
            'color' => 'green',
        ]
    );

    $response->assertForbidden();
});

test('authenticated users can delete their own category', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->delete(route('categories.destroy', $category));

    $response->assertRedirect(route('categories.index'));

    $this->assertSoftDeleted('categories', [
        'id' => $category->id,
    ]);
});

test('users cannot delete categories they do not own', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($user)->delete(route('categories.destroy', $category));

    $response->assertForbidden();

    $this->assertDatabaseHas('categories', [
        'id' => $category->id,
        'deleted_at' => null,
    ]);
});

test('guests cannot access category management', function () {
    $response = $this->get(route('categories.index'));
    $response->assertRedirect(route('login'));

    $response = $this->post(route('categories.store'), []);
    $response->assertRedirect(route('login'));
});

test('default categories are created when user registers', function () {
    $user = User::factory()->create();

    $service = new \App\Actions\CreateDefaultCategories;
    $service->handle($user);

    expect($user->categories()->count())->toBe(8);

    $categoryNames = $user->categories->pluck('name')->toArray();
    expect($categoryNames)->toContain('Groceries', 'Transportation', 'Entertainment');
});
