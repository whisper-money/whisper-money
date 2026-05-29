<?php

use App\Models\Category;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('can view categories page', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/settings/categories');

    $page->assertSee('Categories settings')
        ->assertSee('Manage your transaction categories')
        ->assertNoJavascriptErrors();
});

it('shows existing categories in list', function () {
    $user = User::factory()->onboarded()->create();
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

it('vertically centers category table cells', function () {
    $user = User::factory()->onboarded()->create();
    Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Investments',
        'icon' => 'ChartNoAxesColumnIncreasing',
        'color' => 'lime',
        'type' => 'transfer',
    ]);

    actingAs($user);

    $page = visit('/settings/categories');

    $page->waitForText('Investments')
        ->assertAttributeContains(
            'tbody tr td[data-slot="table-cell"]:first-child',
            'class',
            'align-middle',
        )
        ->assertNoJavascriptErrors();
});

it('can open create category dialog', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/settings/categories');

    $page->assertSee('Categories settings')
        ->click('Create Category')
        ->wait(0.5)
        ->assertSee('Add a new category to organize your transactions')
        ->assertNoJavascriptErrors();
});

it('can create a new category', function () {
    $user = User::factory()->onboarded()->create();

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
        ->assertSee('Cashflow and charts')
        ->assertSee('Expense categories count as cash outflow and appear in spending charts, including top spending categories')
        ->click('button[type="submit"]')
        ->wait(2)
        ->assertSee('Entertainment')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('categories', [
        'user_id' => $user->id,
        'name' => 'Entertainment',
        'color' => 'purple',
        'type' => 'expense',
        'cashflow_direction' => 'hidden',
    ]);
});

it('explains savings and investment category cashflow impact in the create dialog', function (string $type, string $expectedText) {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/settings/categories');

    $page->assertSee('Categories settings')
        ->click('Create Category')
        ->wait(0.5)
        ->click('Select a type')
        ->wait(0.5)
        ->click("//div[@role=\"option\"][contains(., \"{$type}\")]")
        ->wait(0.3)
        ->assertSee('Cashflow and charts')
        ->assertSee($expectedText)
        ->assertSee('stay out of income, expenses, and top spending categories')
        ->assertNoJavascriptErrors();
})->with([
    ['Savings', 'Savings categories appear as saved money at the top of cashflow and as cash outflow in the cashflow chart'],
    ['Investment', 'Investment categories appear as invested money at the top of cashflow and as cash outflow in the cashflow chart'],
]);

it('shows locked income cashflow direction in the create dialog', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/settings/categories');

    $page->assertSee('Categories settings')
        ->click('Create Category')
        ->wait(0.5)
        ->assertSee('Cashflow and charts')
        ->assertSee('Choose a category type to see how it affects cashflow and charts')
        ->click('Select a type')
        ->wait(0.5)
        ->click('//div[@role="option"][contains(., "Income")]')
        ->wait(0.3)
        ->assertSee('Income categories count as cash inflow and appear in income charts')
        ->assertSee('They do not appear in top spending categories')
        ->assertNoJavascriptErrors();
});

it('can create a new transfer category with a cashflow analytics direction', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/settings/categories');

    $page->assertSee('Categories settings')
        ->click('Create Category')
        ->wait(0.5)
        ->fill('name', 'Emergency Fund')
        ->click('Select an icon')
        ->wait(0.5)
        ->click('//div[@role="option"][contains(., "PiggyBank")]')
        ->wait(0.3)
        ->click('Select a color')
        ->wait(0.5)
        ->click('//div[@role="option"][contains(., "lime")]')
        ->wait(0.3)
        ->click('Select a type')
        ->wait(0.5)
        ->click('//div[@role="option"][contains(., "Transfer")]')
        ->wait(0.3)
        ->assertSee('Cashflow and charts')
        ->assertSee('Choose whether to show them in the cashflow chart')
        ->click('Do not show in cashflow chart')
        ->wait(0.5)
        ->click('//div[@role="option"][contains(., "Show in cashflow chart as outflow")]')
        ->wait(0.3)
        ->click('Save')
        ->wait(2)
        ->assertSee('Emergency Fund')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('categories', [
        'user_id' => $user->id,
        'name' => 'Emergency Fund',
        'color' => 'lime',
        'type' => 'transfer',
        'cashflow_direction' => 'outflow',
    ]);
});

it('can filter categories by name', function () {
    $user = User::factory()->onboarded()->create();
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
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/settings/categories');

    $page->assertSee('Categories settings')
        ->waitForText('No categories found')
        ->assertNoJavascriptErrors();
});

it('can edit an existing category via context menu', function () {
    $user = User::factory()->onboarded()->create();
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
    $user = User::factory()->onboarded()->create();

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
        ->assertSee('Transfer categories are excluded from income, expenses, and top spending categories')
        ->assertSee('Choose whether to show them in the cashflow chart')
        ->assertNoJavascriptErrors();
});

it('shows transfer type description when transfer type is selected in edit dialog', function () {
    $user = User::factory()->onboarded()->create();
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
        ->assertSee('Transfer categories are excluded from income, expenses, and top spending categories')
        ->assertSee('Choose whether to show them in the cashflow chart')
        ->assertNoJavascriptErrors();
});
