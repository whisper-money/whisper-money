<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;

test('user can create a budget', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $category = Category::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->post('/budgets', [
        'name' => 'Monthly Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_id' => $category->id,
        'rollover_type' => 'reset',
        'allocated_amount' => 100000,
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('budgets', [
        'user_id' => $user->id,
        'name' => 'Monthly Budget',
        'period_type' => 'monthly',
        'category_id' => $category->id,
    ]);

    $budget = Budget::where('user_id', $user->id)->first();
    $this->assertNotNull($budget);
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

    $category = Category::factory()->create(['user_id' => $user->id]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
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

test('budget show returns previous period when it exists', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'period_start_day' => 1,
    ]);

    // Create a previous period (last month)
    $budget->periods()->create([
        'start_date' => now()->subMonthNoOverflow()->startOfMonth(),
        'end_date' => now()->subMonthNoOverflow()->endOfMonth(),
        'allocated_amount' => 30000,
        'carried_over_amount' => 0,
    ]);

    // Create the current period
    $budget->periods()->create([
        'start_date' => now()->startOfMonth(),
        'end_date' => now()->endOfMonth(),
        'allocated_amount' => 30000,
        'carried_over_amount' => 0,
    ]);

    $response = $this->actingAs($user)->get("/budgets/{$budget->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('budgets/show')
        ->has('currentPeriod')
        ->has('previousPeriod')
        ->where('previousPeriod.start_date', now()->subMonthNoOverflow()->startOfMonth()->toJSON())
    );
});

test('budget show returns null previous period when it is the first period', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'period_start_day' => 1,
    ]);

    // Create only the current period
    $budget->periods()->create([
        'start_date' => now()->startOfMonth(),
        'end_date' => now()->endOfMonth(),
        'allocated_amount' => 30000,
        'carried_over_amount' => 0,
    ]);

    $response = $this->actingAs($user)->get("/budgets/{$budget->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('budgets/show')
        ->has('currentPeriod')
        ->where('previousPeriod', null)
    );
});

test('budget show returns next period only when it starts on or before today', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'period_start_day' => 1,
    ]);

    // Create a previous period (two months ago)
    $budget->periods()->create([
        'start_date' => now()->subMonths(2)->startOfMonth(),
        'end_date' => now()->subMonths(2)->endOfMonth(),
        'allocated_amount' => 20000,
        'carried_over_amount' => 0,
    ]);

    // Create the current period
    $budget->periods()->create([
        'start_date' => now()->startOfMonth(),
        'end_date' => now()->endOfMonth(),
        'allocated_amount' => 30000,
        'carried_over_amount' => 0,
    ]);

    // Create a future period (should be excluded from nextPeriod)
    $budget->periods()->create([
        'start_date' => now()->addMonth()->startOfMonth(),
        'end_date' => now()->addMonth()->endOfMonth(),
        'allocated_amount' => 30000,
        'carried_over_amount' => 0,
    ]);

    // Navigate to the previous period — next should be the current (today), not the future one
    $previousPeriod = $budget->periods()
        ->where('start_date', now()->subMonths(2)->startOfMonth()->toDateString())
        ->first();

    $response = $this->actingAs($user)->get("/budgets/{$budget->id}?period={$previousPeriod->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('budgets/show')
        ->has('currentPeriod')
        ->has('nextPeriod')
        ->where('nextPeriod.start_date', now()->startOfMonth()->toJSON())
    );
});

test('budget show returns null next period when on the latest period', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'period_start_day' => 1,
    ]);

    // Create only the current period (no future period)
    $budget->periods()->create([
        'start_date' => now()->startOfMonth(),
        'end_date' => now()->endOfMonth(),
        'allocated_amount' => 30000,
        'carried_over_amount' => 0,
    ]);

    $response = $this->actingAs($user)->get("/budgets/{$budget->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('budgets/show')
        ->has('currentPeriod')
        ->where('nextPeriod', null)
    );
});

test('budget show can navigate to a specific period via query param', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'period_start_day' => 1,
    ]);

    // Create a previous period
    $previousPeriod = $budget->periods()->create([
        'start_date' => now()->subMonthNoOverflow()->startOfMonth(),
        'end_date' => now()->subMonthNoOverflow()->endOfMonth(),
        'allocated_amount' => 20000,
        'carried_over_amount' => 0,
    ]);

    // Create the current period
    $budget->periods()->create([
        'start_date' => now()->startOfMonth(),
        'end_date' => now()->endOfMonth(),
        'allocated_amount' => 30000,
        'carried_over_amount' => 0,
    ]);

    $response = $this->actingAs($user)->get("/budgets/{$budget->id}?period={$previousPeriod->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('budgets/show')
        ->where('currentPeriod.id', $previousPeriod->id)
        ->where('previousPeriod', null)
        ->has('nextPeriod')
    );
});

test('budget show returns 404 when period does not belong to budget', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $budget1 = Budget::factory()->monthly()->create(['user_id' => $user->id, 'period_start_day' => 1]);
    $budget2 = Budget::factory()->monthly()->create(['user_id' => $user->id, 'period_start_day' => 1]);

    // Create a period for budget2
    $otherPeriod = $budget2->periods()->create([
        'start_date' => now()->startOfMonth(),
        'end_date' => now()->endOfMonth(),
        'allocated_amount' => 30000,
        'carried_over_amount' => 0,
    ]);

    // Try to access budget1 with budget2's period ID
    $response = $this->actingAs($user)->get("/budgets/{$budget1->id}?period={$otherPeriod->id}");

    $response->assertNotFound();
});

test('budget period is automatically generated', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $category = Category::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->post('/budgets', [
        'name' => 'Test Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_id' => $category->id,
        'rollover_type' => 'reset',
        'allocated_amount' => 50000,
    ]);

    $budget = Budget::where('user_id', $user->id)->first();
    $this->assertNotNull($budget);
    $this->assertCount(1, $budget->periods);

    $period = $budget->periods->first();
    $this->assertNotNull($period->start_date);
    $this->assertNotNull($period->end_date);
});
