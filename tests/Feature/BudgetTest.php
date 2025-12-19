<?php

use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use App\Models\Budget;
use App\Models\BudgetCategory;
use App\Models\Category;
use App\Models\User;

test('user can create a budget', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->post('/budgets', [
        'name' => 'Monthly Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'categories' => [
            [
                'category_id' => $category->id,
                'rollover_type' => 'carry_over',
                'label_ids' => [],
            ],
        ],
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('budgets', [
        'user_id' => $user->id,
        'name' => 'Monthly Budget',
        'period_type' => 'monthly',
    ]);

    $budget = Budget::where('user_id', $user->id)->first();
    $this->assertNotNull($budget);
    $this->assertCount(1, $budget->budgetCategories);
    $this->assertCount(1, $budget->periods);
});

test('user can view their budgets', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get('/budgets');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('budgets/index')
        ->has('budgets', 1)
    );
});

test('user can view a specific budget', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);
    BudgetCategory::factory()->create([
        'budget_id' => $budget->id,
        'category_id' => $category->id,
    ]);

    $response = $this->actingAs($user)->get("/budgets/{$budget->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('budgets/show')
        ->has('budget')
        ->has('currentPeriod')
    );
});

test('user cannot view another users budget', function () {
    $user1 = User::factory()->create(['onboarded_at' => now()]);
    $user2 = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create(['user_id' => $user1->id]);

    $response = $this->actingAs($user2)->get("/budgets/{$budget->id}");

    $response->assertForbidden();
});

test('user can update their budget', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->patch("/budgets/{$budget->id}", [
        'name' => 'Updated Budget Name',
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('budgets', [
        'id' => $budget->id,
        'name' => 'Updated Budget Name',
    ]);
});

test('user can delete their budget', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->delete("/budgets/{$budget->id}");

    $response->assertRedirect();

    $this->assertSoftDeleted('budgets', [
        'id' => $budget->id,
    ]);
});

test('budget period is automatically generated', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->post('/budgets', [
        'name' => 'Test Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'categories' => [
            [
                'category_id' => $category->id,
                'rollover_type' => 'reset',
                'label_ids' => [],
            ],
        ],
    ]);

    $budget = Budget::where('user_id', $user->id)->first();
    $this->assertNotNull($budget);
    $this->assertCount(1, $budget->periods);

    $period = $budget->periods->first();
    $this->assertNotNull($period->start_date);
    $this->assertNotNull($period->end_date);
});
