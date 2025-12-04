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
        ->waitForText('Groceries')
        ->assertNoJavascriptErrors();
});

it('can open create category dialog', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);

    actingAs($user);

    $page = visit('/settings/categories');

    $page->assertSee('Categories settings')
        ->click('Create Category')
        ->wait(0.5)
        ->assertSee('Add a new category to organize your transactions')
        ->assertNoJavascriptErrors();
});

it('can create a new category', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);

    actingAs($user);

    $page = visit('/settings/categories');

    $page->assertSee('Categories settings')
        ->click('Create Category')
        ->wait(0.5)
        ->fill('name', 'Entertainment')
        ->click('Select an icon')
        ->wait(0.5)
        ->click('//div[@role="option"][1]')
        ->wait(0.3)
        ->click('Select a color')
        ->wait(0.5)
        ->click('//div[@role="option"][contains(., "purple")]')
        ->wait(0.3)
        ->click('Select a type')
        ->wait(0.5)
        ->click('//div[@role="option"][contains(., "Expense")]')
        ->wait(0.3)
        ->click('button[type="submit"]')
        ->wait(2)
        ->assertSee('Entertainment')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('categories', [
        'user_id' => $user->id,
        'name' => 'Entertainment',
        'color' => 'purple',
        'type' => 'expense',
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
        ->waitForText('Groceries')
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
        ->waitForText('No categories found')
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

    $page->waitForText('Old Category')
        ->rightClick('Old Category')
        ->wait(0.5)
        ->click('Edit')
        ->wait(0.5)
        ->assertSee('Edit Category')
        ->fill('name', 'Updated Category')
        ->click('//button[contains(., "Update")]')
        ->wait(2)
        ->assertSee('Updated Category')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('categories', [
        'id' => $category->id,
        'name' => 'Updated Category',
    ]);
});

it('shows transfer type description when transfer type is selected in create dialog', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);

    actingAs($user);

    $page = visit('/settings/categories');

    $page->assertSee('Categories settings')
        ->click('Create Category')
        ->wait(1)
        ->assertSee('Add a new category to organize your transactions')
        ->assertSee('Select a type')
        ->click('//button[contains(., "Select a type")]')
        ->wait(0.5)
        ->click('//div[@role="option"][contains(., "Transfer")]')
        ->wait(0.5)
        ->assertSee('Transactions in this category will not be counted in top expenses or income')
        ->assertSee('Transfer categories are mainly used for transactions between accounts')
        ->assertNoJavascriptErrors();
});

it('shows transfer type description when transfer type is selected in edit dialog', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Test Category',
        'icon' => 'Tag',
        'color' => 'blue',
        'type' => 'expense',
    ]);

    actingAs($user);

    $page = visit('/settings/categories');

    $page->waitForText('Test Category')
        ->rightClick('Test Category')
        ->wait(0.5)
        ->click('Edit')
        ->wait(1)
        ->assertSee('Edit Category')
        ->click('//button[contains(., "Expense")]')
        ->wait(0.5)
        ->click('//div[@role="option"][contains(., "Transfer")]')
        ->wait(0.5)
        ->assertSee('Transactions in this category will not be counted in top expenses or income')
        ->assertSee('Transfer categories are mainly used for transactions between accounts')
        ->assertNoJavascriptErrors();
});
