<?php

use App\Actions\CreateDefaultCategories;
use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Http\Controllers\Settings\CategoryController;
use App\Http\Requests\Settings\StoreCategoryRequest;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

test('category names must be unique for each user when creating', function () {
    $user = User::factory()->create();

    Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Healthcare',
    ]);

    $response = $this->actingAs($user)->post(route('categories.store'), [
        'name' => 'Healthcare',
        'icon' => 'Heart',
        'color' => 'pink',
        'type' => 'expense',
        'cashflow_direction' => 'hidden',
    ]);

    $response->assertSessionHasErrors(['name']);

    expect($user->categories()->where('name', 'Healthcare')->count())->toBe(1);
});

test('different users can create categories with the same name', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Category::factory()->create([
        'user_id' => $otherUser->id,
        'name' => 'Healthcare',
    ]);

    $response = $this->actingAs($user)->post(route('categories.store'), [
        'name' => 'Healthcare',
        'icon' => 'Heart',
        'color' => 'pink',
        'type' => 'expense',
        'cashflow_direction' => 'hidden',
    ]);

    $response->assertRedirect(route('categories.index'));

    $this->assertDatabaseHas('categories', [
        'user_id' => $user->id,
        'name' => 'Healthcare',
    ]);
});

test('users can recreate a category with the same name after deleting it', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Supermercados',
    ]);

    $category->delete();

    $response = $this->actingAs($user)->post(route('categories.store'), [
        'name' => 'Supermercados',
        'icon' => 'ShoppingCart',
        'color' => 'green',
        'type' => 'expense',
        'cashflow_direction' => 'hidden',
    ]);

    $response->assertRedirect(route('categories.index'));

    expect($user->categories()->where('name', 'Supermercados')->count())->toBe(1)
        ->and($user->categories()->withTrashed()->where('name', 'Supermercados')->count())->toBe(2);
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

test('users can keep their category name when updating', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Healthcare',
        'type' => 'expense',
    ]);

    $response = $this->actingAs($user)->patch(
        route('categories.update', $category),
        [
            'name' => 'Healthcare',
            'icon' => 'Heart',
            'color' => 'pink',
            'type' => 'expense',
            'cashflow_direction' => 'hidden',
        ]
    );

    $response->assertRedirect(route('categories.index'));

    $this->assertDatabaseHas('categories', [
        'id' => $category->id,
        'name' => 'Healthcare',
        'color' => 'pink',
    ]);
});

test('category names must be unique for each user when updating', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Old Name',
    ]);
    Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Healthcare',
    ]);

    $response = $this->actingAs($user)->patch(
        route('categories.update', $category),
        [
            'name' => 'Healthcare',
            'icon' => 'Heart',
            'color' => 'pink',
            'type' => 'expense',
            'cashflow_direction' => 'hidden',
        ]
    );

    $response->assertSessionHasErrors(['name']);

    $this->assertDatabaseHas('categories', [
        'id' => $category->id,
        'name' => 'Old Name',
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

test('users can create savings and investment categories', function (string $type) {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('categories.store'), [
        'name' => ucfirst($type),
        'icon' => $type === CategoryType::Savings->value ? 'PiggyBank' : 'TrendingUp',
        'color' => 'lime',
        'type' => $type,
        'cashflow_direction' => 'outflow',
    ]);

    $response->assertRedirect(route('categories.index'));

    $this->assertDatabaseHas('categories', [
        'user_id' => $user->id,
        'name' => ucfirst($type),
        'type' => $type,
        'cashflow_direction' => CategoryCashflowDirection::Outflow->value,
    ]);
})->with([
    CategoryType::Savings->value,
    CategoryType::Investment->value,
]);

test('migration updates existing default saving and investment categories', function () {
    $user = User::factory()->create();

    $investments = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Investments',
        'icon' => 'LineChart',
        'color' => 'lime',
        'type' => CategoryType::Transfer,
        'cashflow_direction' => CategoryCashflowDirection::Outflow,
    ]);
    $savings = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Savings',
        'icon' => 'PiggyBank',
        'color' => 'lime',
        'type' => CategoryType::Transfer,
        'cashflow_direction' => CategoryCashflowDirection::Outflow,
    ]);
    $legacyExpenseInvestment = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Other investments',
        'icon' => 'TrendingUp',
        'color' => 'lime',
        'type' => CategoryType::Expense,
        'cashflow_direction' => CategoryCashflowDirection::Hidden,
    ]);
    $legacySpanishSavings = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Ahorros',
        'icon' => 'PiggyBank',
        'color' => 'lime',
        'type' => CategoryType::Expense,
        'cashflow_direction' => CategoryCashflowDirection::Hidden,
    ]);
    $customTransfer = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Investment transfer',
        'icon' => 'LineChart',
        'color' => 'lime',
        'type' => CategoryType::Transfer,
        'cashflow_direction' => CategoryCashflowDirection::Outflow,
    ]);

    $migration = require database_path('migrations/2026_05_25_115100_update_default_saving_and_investment_category_types.php');
    $migration->up();

    expect($investments->refresh())
        ->type->toBe(CategoryType::Investment)
        ->cashflow_direction->toBe(CategoryCashflowDirection::Hidden);
    expect($savings->refresh())
        ->type->toBe(CategoryType::Savings)
        ->cashflow_direction->toBe(CategoryCashflowDirection::Hidden);
    expect($legacyExpenseInvestment->refresh())
        ->type->toBe(CategoryType::Investment)
        ->cashflow_direction->toBe(CategoryCashflowDirection::Hidden);
    expect($legacySpanishSavings->refresh())
        ->type->toBe(CategoryType::Savings)
        ->cashflow_direction->toBe(CategoryCashflowDirection::Hidden);
    expect($customTransfer->refresh())
        ->type->toBe(CategoryType::Transfer)
        ->cashflow_direction->toBe(CategoryCashflowDirection::Outflow);
});

test('migration sets saving and investment categories to cashflow outflow', function () {
    $user = User::factory()->create();

    $savings = Category::factory()->create([
        'user_id' => $user->id,
        'type' => CategoryType::Savings,
        'cashflow_direction' => CategoryCashflowDirection::Hidden,
    ]);
    $investment = Category::factory()->create([
        'user_id' => $user->id,
        'type' => CategoryType::Investment,
        'cashflow_direction' => CategoryCashflowDirection::Hidden,
    ]);
    $transfer = Category::factory()->create([
        'user_id' => $user->id,
        'type' => CategoryType::Transfer,
        'cashflow_direction' => CategoryCashflowDirection::Inflow,
    ]);
    $expense = Category::factory()->create([
        'user_id' => $user->id,
        'type' => CategoryType::Expense,
        'cashflow_direction' => CategoryCashflowDirection::Hidden,
    ]);

    $migration = require database_path('migrations/2026_05_29_085835_update_saving_and_investment_category_cashflow_direction.php');
    $migration->up();

    expect($savings->refresh()->cashflow_direction)->toBe(CategoryCashflowDirection::Outflow);
    expect($investment->refresh()->cashflow_direction)->toBe(CategoryCashflowDirection::Outflow);
    expect($transfer->refresh()->cashflow_direction)->toBe(CategoryCashflowDirection::Inflow);
    expect($expense->refresh()->cashflow_direction)->toBe(CategoryCashflowDirection::Hidden);

    $migration->down();

    expect($savings->refresh()->cashflow_direction)->toBe(CategoryCashflowDirection::Hidden);
    expect($investment->refresh()->cashflow_direction)->toBe(CategoryCashflowDirection::Hidden);
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
        ->type->toBe(CategoryType::Investment)
        ->cashflow_direction->toBe(CategoryCashflowDirection::Outflow);
    expect($user->categories()->firstWhere('name', 'Savings'))
        ->type->toBe(CategoryType::Savings)
        ->cashflow_direction->toBe(CategoryCashflowDirection::Outflow);
    expect($user->categories()->firstWhere('name', 'Other investments'))
        ->type->toBe(CategoryType::Investment)
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

test('default categories are created without repeated category lookups', function () {
    $user = User::factory()->create();
    $service = new CreateDefaultCategories;
    $categorySelects = 0;

    DB::listen(function ($query) use (&$categorySelects) {
        if (str_starts_with($query->sql, 'select `name` from `categories`')) {
            $categorySelects++;
        }
    });

    $service->handle($user);

    expect($user->categories()->count())->toBe(64)
        ->and($categorySelects)->toBe(1);
});

test('duplicate category name database errors become validation errors', function () {
    $user = User::factory()->create();
    $controller = new CategoryController;

    $user->categories()->create([
        'name' => 'Healthcare',
        'icon' => 'Heart',
        'color' => 'pink',
        'type' => 'expense',
    ]);

    $request = Mockery::mock(StoreCategoryRequest::class);
    $request->shouldReceive('validated')->once()->andReturn([
        'name' => 'Healthcare',
        'icon' => 'Heart',
        'color' => 'pink',
        'type' => 'expense',
        'cashflow_direction' => 'hidden',
    ]);

    $this->actingAs($user);

    $thrown = null;

    try {
        $controller->store($request);
    } catch (ValidationException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(ValidationException::class);
    expect($thrown->errors())->toHaveKey('name');
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
