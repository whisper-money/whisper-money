<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Laravel\Pennant\Feature;

test('user can create a budget with category', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Feature::for($user)->activate('budgets');

    $category = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Groceries',
    ]);

    $page = $this->actingAs($user)->visit('/budgets');

    $page->assertSee('Budgets')
        ->click('Create Budget')
        ->wait(1)
        ->assertSee('Create Budget')
        ->fill('name', 'Monthly Groceries')
        ->select('period_type', 'monthly')
        ->select('category_id', $category->id)
        ->select('rollover_type', 'reset')
        ->fill('allocated_amount', '50000')
        ->click('button[type="submit"]')
        ->wait(2)
        ->assertSee('Monthly Groceries')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('budgets', [
        'user_id' => $user->id,
        'name' => 'Monthly Groceries',
        'category_id' => $category->id,
    ]);
});

test('user can update budget name', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Feature::for($user)->activate('budgets');

    $category = Category::factory()->create(['user_id' => $user->id]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'name' => 'Old Name',
    ]);

    $page = $this->actingAs($user)->visit("/budgets/{$budget->id}");

    $page->assertSee('Old Name')
        ->click('Edit')
        ->wait(1)
        ->assertSee('Edit Budget')
        ->fill('name', 'New Budget Name')
        ->click('button[type="submit"]')
        ->wait(2)
        ->assertSee('New Budget Name')
        ->assertDontSee('Old Name')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('budgets', [
        'id' => $budget->id,
        'name' => 'New Budget Name',
    ]);
});

test('user can delete a budget', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Feature::for($user)->activate('budgets');

    $category = Category::factory()->create(['user_id' => $user->id]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'name' => 'Budget to Delete',
    ]);

    $page = $this->actingAs($user)->visit("/budgets/{$budget->id}");

    $page->assertSee('Budget to Delete')
        ->click('Delete')
        ->wait(1)
        ->assertSee('Delete Budget')
        ->assertSee('Are you sure')
        ->click('button[type="submit"]')
        ->wait(2)
        ->assertPathIs('/budgets')
        ->assertDontSee('Budget to Delete')
        ->assertNoJavascriptErrors();

    $this->assertSoftDeleted('budgets', [
        'id' => $budget->id,
    ]);
});

test('budget creation validates required fields', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Feature::for($user)->activate('budgets');

    $page = $this->actingAs($user)->visit('/budgets');

    $page->assertSee('Budgets')
        ->click('Create Budget')
        ->wait(1)
        ->assertSee('Create Budget')
        ->click('button[type="submit"]')
        ->wait(1)
        ->assertSee('The name field is required')
        ->assertNoJavascriptErrors();
});

test('budget shows current period information', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Feature::for($user)->activate('budgets');

    $category = Category::factory()->create(['user_id' => $user->id]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
    ]);

    $page = $this->actingAs($user)->visit("/budgets/{$budget->id}");

    $page->assertSee($budget->name)
        ->assertSee('Tracking')
        ->assertNoJavascriptErrors();
});

test('user can navigate back to budgets list from budget detail', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Feature::for($user)->activate('budgets');

    $category = Category::factory()->create(['user_id' => $user->id]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
    ]);

    $page = $this->actingAs($user)->visit("/budgets/{$budget->id}");

    $page->assertSee($budget->name)
        ->click('Budgets')
        ->wait(2)
        ->assertPathIs('/budgets')
        ->assertNoJavascriptErrors();
});
