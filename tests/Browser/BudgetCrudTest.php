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
    $page->wait(2); // Wait for page to fully load

    $page->assertSee('Budgets')
        ->waitForText('Create Budget', 10)
        ->wait(1) // Extra wait before clicking
        ->click('Create Budget')
        ->wait(3) // Wait for dialog to open
        ->assertSee('Create Budget')
        ->wait(1) // Wait for form to be ready
        ->fill('name', 'Monthly Groceries')
        ->wait(1)
        ->select('period_type', 'monthly')
        ->wait(1)
        ->select('category_id', $category->id)
        ->wait(1)
        ->select('rollover_type', 'reset')
        ->wait(1)
        ->fill('allocated_amount', '50000')
        ->wait(2)
        ->click('button[type="submit"]')
        ->wait(4) // Wait for form submission
        ->assertPathIs('/budgets')
        ->wait(2) // Wait for page to update
        ->waitForText('Monthly Groceries', 15)
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
    $page->wait(2); // Wait for page to fully load

    $page->assertSee('Old Name')
        ->wait(2)
        ->waitForText('Edit', 10)
        ->wait(1) // Extra wait before clicking
        ->click('Edit')
        ->wait(3) // Wait for dialog to open
        ->assertSee('Edit Budget')
        ->wait(1) // Wait for form to be ready
        ->fill('name', 'New Budget Name')
        ->wait(2)
        ->click('button[type="submit"]')
        ->wait(4) // Wait for form submission
        ->waitForText('New Budget Name', 15)
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
    $page->wait(2); // Wait for page to fully load

    $page->assertSee('Budget to Delete')
        ->wait(2)
        ->waitForText('Delete', 10)
        ->wait(1) // Extra wait before clicking
        ->click('Delete')
        ->wait(3) // Wait for dialog to open
        ->assertSee('Delete Budget')
        ->assertSee('Are you sure')
        ->wait(2)
        ->click('button[type="submit"]')
        ->wait(4) // Wait for deletion
        ->assertPathIs('/budgets')
        ->wait(2) // Wait for page to update
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
    $page->wait(2); // Wait for page to fully load

    $page->assertSee('Budgets')
        ->waitForText('Create Budget', 10)
        ->wait(1) // Extra wait before clicking
        ->click('Create Budget')
        ->wait(3) // Wait for dialog to open
        ->assertSee('Create Budget')
        ->wait(2) // Wait for form to be ready
        ->click('button[type="submit"]')
        ->wait(3) // Wait for validation
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
    $page->wait(2); // Wait for page to fully load

    $page->assertSee($budget->name)
        ->wait(2)
        ->waitForText('Budgets', 10)
        ->wait(1) // Extra wait before clicking
        ->click('Budgets')
        ->wait(4) // Wait for navigation
        ->assertPathIs('/budgets')
        ->assertNoJavascriptErrors();
});
