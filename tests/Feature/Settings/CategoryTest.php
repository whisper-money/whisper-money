<?php

use App\Actions\CreateDefaultCategories;
use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function () {
    config(['landing.hide_auth_buttons' => false]);
});

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
        'icon' => 'ShoppingBag',
        'color' => 'blue',
        'type' => 'expense',
        'cashflow_direction' => 'outflow',
    ];

    $response = $this->actingAs($user)->post(route('categories.store'), $categoryData);

    $response->assertRedirect(route('categories.index'));

    $this->assertDatabaseHas('categories', [
        'user_id' => $user->id,
        'name' => 'Shopping',
        'icon' => 'ShoppingBag',
        'color' => 'blue',
        'type' => 'expense',
        'cashflow_direction' => CategoryCashflowDirection::Hidden->value,
    ]);
});

test('category name is required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('categories.store'), [
        'icon' => 'ShoppingBag',
        'color' => 'blue',
        'type' => 'expense',
        'cashflow_direction' => 'hidden',
    ]);

    $response->assertSessionHasErrors(['name']);
});

test('category icon is required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('categories.store'), [
        'name' => 'Shopping',
        'color' => 'blue',
        'type' => 'expense',
    ]);

    $response->assertSessionHasErrors(['icon']);
});

test('category color is required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('categories.store'), [
        'name' => 'Shopping',
        'icon' => 'ShoppingBag',
        'type' => 'expense',
    ]);

    $response->assertSessionHasErrors(['color']);
});

test('category color must be valid', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('categories.store'), [
        'name' => 'Shopping',
        'icon' => 'ShoppingBag',
        'color' => 'invalid-color',
        'type' => 'expense',
    ]);

    $response->assertSessionHasErrors(['color']);
});

test('category type is required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('categories.store'), [
        'name' => 'Shopping',
        'icon' => 'ShoppingBag',
        'color' => 'blue',
    ]);

    $response->assertSessionHasErrors(['type']);
});

test('category type must be valid', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('categories.store'), [
        'name' => 'Shopping',
        'icon' => 'ShoppingBag',
        'color' => 'blue',
        'type' => 'invalid-type',
    ]);

    $response->assertSessionHasErrors(['type']);
});

test('authenticated users can update their own category', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    $updateData = [
        'name' => 'Updated Name',
        'icon' => 'Home',
        'color' => 'green',
        'type' => 'transfer',
        'cashflow_direction' => 'outflow',
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
        'type' => 'transfer',
        'cashflow_direction' => 'outflow',
    ]);
});

test('transfer categories can store a cashflow direction', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('categories.store'), [
        'name' => 'Investment',
        'icon' => 'TrendingUp',
        'color' => 'green',
        'type' => 'transfer',
        'cashflow_direction' => 'outflow',
    ]);

    $response->assertRedirect(route('categories.index'));

    $this->assertDatabaseHas('categories', [
        'user_id' => $user->id,
        'name' => 'Investment',
        'type' => 'transfer',
        'cashflow_direction' => 'outflow',
    ]);
});

test('non-transfer categories are forced to hidden cashflow direction', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('categories.store'), [
        'name' => 'Salary',
        'icon' => 'Coins',
        'color' => 'green',
        'type' => 'income',
        'cashflow_direction' => 'outflow',
    ]);

    $response->assertRedirect(route('categories.index'));

    $this->assertDatabaseHas('categories', [
        'user_id' => $user->id,
        'name' => 'Salary',
        'type' => 'income',
        'cashflow_direction' => CategoryCashflowDirection::Hidden->value,
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
            'type' => 'expense',
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
    $response->assertRedirect(route('register'));

    $response = $this->post(route('categories.store'), []);
    $response->assertRedirect(route('register'));
});

test('default categories are created when user registers', function () {
    $user = User::factory()->create();

    $service = new CreateDefaultCategories;
    $service->handle($user);

    expect($user->categories()->count())->toBe(64);

    $categoryNames = $user->categories->pluck('name')->toArray();
    expect($categoryNames)->toContain('Food', 'Transportation', 'Salary', 'Insurance');

    $user->refresh();

    expect($user->categories()->firstWhere('name', 'Investments'))
        ->type->toBe(CategoryType::Transfer)
        ->cashflow_direction->toBe(CategoryCashflowDirection::Outflow);
    expect($user->categories()->firstWhere('name', 'Savings'))
        ->type->toBe(CategoryType::Transfer)
        ->cashflow_direction->toBe(CategoryCashflowDirection::Outflow);
    expect($user->categories()->firstWhere('name', 'Other investments'))
        ->type->toBe(CategoryType::Transfer)
        ->cashflow_direction->toBe(CategoryCashflowDirection::Outflow);
    expect($user->categories()->firstWhere('name', 'From account of relatives'))
        ->type->toBe(CategoryType::Transfer)
        ->cashflow_direction->toBe(CategoryCashflowDirection::Inflow);
});

test('default categories are not created twice for the same user', function () {
    $user = User::factory()->create();

    $service = new CreateDefaultCategories;
    $service->handle($user);

    expect($user->categories()->count())->toBe(64);

    $service->handle($user);

    expect($user->categories()->count())->toBe(64);
});

test('category names are unique per user', function () {
    $user = User::factory()->create();

    $category = $user->categories()->create([
        'name' => 'Test Category',
        'icon' => 'Tag',
        'color' => 'red',
        'type' => 'expense',
    ]);

    expect($category)->toBeInstanceOf(Category::class);

    $this->expectException(UniqueConstraintViolationException::class);

    $user->categories()->create([
        'name' => 'Test Category',
        'icon' => 'Tag',
        'color' => 'blue',
        'type' => 'expense',
    ]);
});
