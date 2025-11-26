<?php

use App\Models\Category;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('can view categories page', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);

    actingAs($user);

    $page = visit('/settings/categories');

    $page->assertSee('Categories settings')
        ->assertSee('Manage your transaction categories')
        ->assertNoJavascriptErrors();
});

it('shows existing categories in list', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Groceries',
        'icon' => 'ShoppingCart',
        'color' => 'green',
    ]);

    actingAs($user);

    $page = visit('/settings/categories');

    $page->assertSee('Categories settings')
        ->assertSee('Groceries')
        ->assertNoJavascriptErrors();
});

it('can open create category dialog', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);

    actingAs($user);

    $page = visit('/settings/categories');

    $page->assertSee('Categories settings')
        ->click('Create Category')
        ->waitFor('dialog')
        ->assertSee('Add a new category to organize your transactions')
        ->assertNoJavascriptErrors();
});

it('can create a new category', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);

    actingAs($user);

    $page = visit('/settings/categories');

    $page->assertSee('Categories settings')
        ->click('Create Category')
        ->waitFor('dialog')
        ->fill('name', 'Entertainment')
        ->click('Select an icon')
        ->wait(0.3)
        ->click('Film')
        ->click('Select a color')
        ->wait(0.3)
        ->click('purple')
        ->click('button[type="submit"]')
        ->wait(2)
        ->assertSee('Entertainment')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('categories', [
        'user_id' => $user->id,
        'name' => 'Entertainment',
        'icon' => 'Film',
        'color' => 'purple',
    ]);
});

it('can filter categories by name', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Groceries',
    ]);
    Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Entertainment',
    ]);

    actingAs($user);

    $page = visit('/settings/categories');

    $page->assertSee('Categories settings')
        ->fill('input[placeholder="Filter categories..."]', 'Groceries')
        ->wait(0.5)
        ->assertSee('Groceries')
        ->assertNoJavascriptErrors();
});

it('shows empty state when no categories exist', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);

    actingAs($user);

    $page = visit('/settings/categories');

    $page->assertSee('Categories settings')
        ->assertSee('No categories found')
        ->assertNoJavascriptErrors();
});

it('can edit an existing category via context menu', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Old Category',
        'icon' => 'Tag',
        'color' => 'blue',
    ]);

    actingAs($user);

    $page = visit('/settings/categories');

    $page->assertSee('Old Category')
        ->click('button[aria-label="Open menu"]')
        ->wait(0.3)
        ->click('Edit')
        ->waitFor('dialog')
        ->assertSee('Edit Category')
        ->fill('name', 'Updated Category')
        ->click('Save')
        ->wait(2)
        ->assertSee('Updated Category')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('categories', [
        'id' => $category->id,
        'name' => 'Updated Category',
    ]);
});
