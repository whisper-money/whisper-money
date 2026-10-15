<?php

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\User;

test('catch-all toggle hides category and label selection', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Groceries',
    ]);

    $page = $this->actingAs($user)->visit('/budgets');

    $page->assertSee('Planning')
        ->waitForText('Create', 10)
        ->click('Create')
        ->click('[role="menuitem"]:has-text("Budget")')
        ->assertSee('Create Budget')
        ->assertSee('Catch-all budget')
        ->assertSee('Categories')
        ->screenshot(filename: 'catch-all-create-default')
        ->fill('name', 'Everything Else')
        ->fill('#allocated-amount', '500')
        ->click('label:has-text("Budget Name")')
        ->click('label:has-text("Catch-all budget")')
        ->wait(1)
        ->assertDontSee('Select categories')
        ->assertDontSee('Select labels')
        ->screenshot(filename: 'catch-all-create-enabled')
        ->assertButtonEnabled('[role="dialog"] button[type="submit"]')
        ->assertNoJavascriptErrors();
});

test('user can create a catch-all budget', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $page = $this->actingAs($user)->visit('/budgets');

    $page->waitForText('Create', 10)
        ->click('Create')
        ->click('[role="menuitem"]:has-text("Budget")')
        ->fill('name', 'Everything Else')
        ->click('label:has-text("Catch-all budget")')
        ->fill('#allocated-amount', '500')
        ->click('label:has-text("Budget Name")')
        ->click('[role="dialog"] button[type="submit"]')
        ->assertPathBeginsWith('/budgets/')
        ->waitForText('Everything Else', 15)
        ->assertNoJavascriptErrors();

    $budget = Budget::where('user_id', $user->id)->where('name', 'Everything Else')->first();
    expect($budget)->not->toBeNull()
        ->and($budget->is_catch_all)->toBeTrue();
});

test('catch-all budget is labelled in the budget list', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $budget = Budget::factory()->catchAll()->monthly()->create([
        'user_id' => $user->id,
        'name' => 'Everything Else',
    ]);

    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->startOfMonth(),
        'end_date' => now()->endOfMonth(),
        'allocated_amount' => 50000,
    ]);

    $page = $this->actingAs($user)->visit('/budgets');

    $page->assertSee('Everything Else')
        ->waitForText('All untracked expenses', 10)
        ->screenshot(filename: 'catch-all-budget-list-card')
        ->assertNoJavascriptErrors();
});

test('edit dialog shows the catch-all budget as read-only tracking', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $budget = Budget::factory()->catchAll()->monthly()->create([
        'user_id' => $user->id,
        'name' => 'Everything Else',
    ]);

    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->startOfMonth(),
        'end_date' => now()->endOfMonth(),
        'allocated_amount' => 50000,
    ]);

    $page = $this->actingAs($user)->visit("/budgets/{$budget->id}");

    $page->assertSee('Everything Else')
        ->click('[aria-label="More options"]')
        ->click('Edit budget')
        ->assertSee('Edit Budget')
        ->assertSee('All untracked expenses')
        ->assertSee('This catch-all budget tracks every expense that no other budget covers.')
        ->screenshot(filename: 'catch-all-edit-dialog')
        ->assertNoJavascriptErrors();
});
