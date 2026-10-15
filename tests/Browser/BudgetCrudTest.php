<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;

test('user can create a budget with category', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $category = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Groceries',
    ]);

    $page = $this->actingAs($user)->visit('/budgets');

    $page->assertSee('Planning')
        ->waitForText('Create', 10)
        ->click('Create')
        ->click('[role="menuitem"]:has-text("Budget")')
        ->assertSee('Create Budget')
        ->fill('name', 'Monthly Groceries')
        ->click('#period-type')
        ->click('[role="option"]:has-text("Monthly")')
        ->click('button:has-text("Select categories")')
        ->click('[role="option"]:has-text("'.$category->name.'")')
        ->click('#categories') // Close the categories combobox popover
        ->wait(0.5)
        ->click('button:has-text("Carry Over")')
        ->click('[role="option"]:has-text("Reset/Pool")')
        ->fill('#allocated-amount', '500')
        ->click('label:has-text("Rollover Type")') // Click elsewhere to blur the amount input
        ->wait(1)
        ->click('[role="dialog"] button[type="submit"]')
        ->assertPathBeginsWith('/budgets/')
        ->waitForText('Monthly Groceries', 15)
        ->assertSee('Budget Spending')
        ->assertNoJavascriptErrors();

    $budget = Budget::where('user_id', $user->id)->where('name', 'Monthly Groceries')->first();
    expect($budget)->not->toBeNull()
        ->and($budget->categories->pluck('id')->contains($category->id))->toBeTrue();
});

test('user can update budget name', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $category = Category::factory()->create(['user_id' => $user->id]);
    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $user->id,
        'name' => 'Old Name',
    ]);

    $page = $this->actingAs($user)->visit("/budgets/{$budget->id}");

    $page->assertSee('Old Name')
        ->click('[aria-label="More options"]')
        ->click('Edit budget')
        ->wait(1)
        ->assertSee('Edit Budget')
        ->fill('name', 'New Budget Name')
        ->fill('#allocated-amount', '500')
        ->click('button:has-text("Save Changes")')
        ->assertTitleContains('New Budget Name')
        ->waitForEvent('networkidle')
        ->assertScript("document.querySelector('[role=\"dialog\"]') === null")
        ->assertPathIs("/budgets/{$budget->id}")
        ->assertSee('Budget Spending')
        ->assertSee('New Budget Name')
        ->assertTitleContains('New Budget Name')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('budgets', [
        'id' => $budget->id,
        'name' => 'New Budget Name',
    ]);
});

test('user can delete a budget', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $category = Category::factory()->create(['user_id' => $user->id]);
    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $user->id,
        'name' => 'Budget to Delete',
    ]);

    $page = $this->actingAs($user)->visit("/budgets/{$budget->id}");

    $page->assertSee('Budget to Delete')
        ->click('[aria-label="More options"]')
        ->click('Delete budget')
        ->assertSee('Delete Budget')
        ->assertSee('Are you sure')
        ->click('button:has-text("Delete")')
        ->assertPathIs('/budgets')
        ->waitForText('Planning', 10)
        ->wait(1)
        ->assertDontSee('Budget to Delete')
        ->assertNoJavascriptErrors();

    $this->assertSoftDeleted('budgets', [
        'id' => $budget->id,
    ]);
});

test('budget creation validates required fields', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $page = $this->actingAs($user)->visit('/budgets');

    $page->assertSee('Planning')
        ->waitForText('Create', 10)
        ->click('Create')
        ->click('[role="menuitem"]:has-text("Budget")')
        ->assertSee('Create Budget')
        ->assertAttribute('button[type="submit"]:has-text("Create Budget")', 'disabled', '')
        ->assertNoJavascriptErrors();
});

test('budget shows current period information', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $category = Category::factory()->create(['user_id' => $user->id]);
    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $user->id,
    ]);

    $page = $this->actingAs($user)->visit("/budgets/{$budget->id}");

    $page->assertSee($budget->name)
        ->assertSee('Tracking')
        ->assertNoJavascriptErrors();
});

test('user can navigate back to budgets list from budget detail', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $category = Category::factory()->create(['user_id' => $user->id]);
    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $user->id,
    ]);

    $page = $this->actingAs($user)->visit("/budgets/{$budget->id}");

    $page->assertSee($budget->name)
        ->waitForText('Planning', 10)
        ->click('nav[aria-label="breadcrumb"] a:has-text("Planning")')
        ->assertPathIs('/budgets')
        ->assertNoJavascriptErrors();
});
