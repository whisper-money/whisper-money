<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\Label;
use App\Models\User;

test('user can create a budget', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $category = Category::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->post('/budgets', [
        'name' => 'Monthly Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [$category->id],
        'rollover_type' => 'reset',
        'allocated_amount' => 100000,
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('budgets', [
        'user_id' => $user->id,
        'name' => 'Monthly Budget',
        'period_type' => 'monthly',
    ]);

    $budget = Budget::where('user_id', $user->id)->first();
    $this->assertNotNull($budget);
    $this->assertTrue($budget->categories->pluck('id')->contains($category->id));
    $this->assertCount(2, $budget->periods);
});

test('user can create a yearly budget', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $category = Category::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->post('/budgets', [
        'name' => 'Yearly Budget',
        'period_type' => 'yearly',
        'period_start_day' => 1,
        'category_ids' => [$category->id],
        'rollover_type' => 'reset',
        'allocated_amount' => 1200000,
    ]);

    $response->assertRedirect();

    $budget = Budget::where('user_id', $user->id)->where('period_type', 'yearly')->first();

    $currentPeriod = $budget->getCurrentPeriod();

    expect($budget)->not->toBeNull()
        ->and($budget->periods()->count())->toBe(2)
        ->and($currentPeriod->start_date->toDateString())->toBe(now()->startOfYear()->toDateString())
        ->and($currentPeriod->end_date->toDateString())->toBe(now()->endOfYear()->toDateString());
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
    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $user->id,
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
        'category_ids' => [$category->id],
        'rollover_type' => 'reset',
        'allocated_amount' => 50000,
    ]);

    $budget = Budget::where('user_id', $user->id)->first();
    $this->assertNotNull($budget);
    $this->assertCount(2, $budget->periods);

    $period = $budget->getCurrentPeriod();
    $this->assertNotNull($period->start_date);
    $this->assertNotNull($period->end_date);
});

test('user can create a budget tracking multiple categories and labels', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $food = Category::factory()->create(['user_id' => $user->id]);
    $restaurants = Category::factory()->create(['user_id' => $user->id]);
    $trip = Label::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->post('/budgets', [
        'name' => 'Food Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [$food->id, $restaurants->id],
        'label_ids' => [$trip->id],
        'rollover_type' => 'reset',
        'allocated_amount' => 80000,
    ]);

    $response->assertRedirect();

    $budget = Budget::where('user_id', $user->id)->first();

    expect($budget->categories->pluck('id')->all())
        ->toEqualCanonicalizing([$food->id, $restaurants->id])
        ->and($budget->labels->pluck('id')->all())->toEqualCanonicalizing([$trip->id]);
});

test('creating a budget requires at least one category or label', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $response = $this->actingAs($user)->post('/budgets', [
        'name' => 'Empty Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [],
        'label_ids' => [],
        'rollover_type' => 'reset',
        'allocated_amount' => 50000,
    ]);

    $response->assertSessionHasErrors('selection');
    expect(Budget::where('user_id', $user->id)->count())->toBe(0);
});

test('user can create a catch-all budget without categories or labels', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $response = $this->actingAs($user)->post('/budgets', [
        'name' => 'Everything Else',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [],
        'label_ids' => [],
        'rollover_type' => 'reset',
        'allocated_amount' => 50000,
        'is_catch_all' => true,
    ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    $budget = Budget::where('user_id', $user->id)->first();
    expect($budget)->not->toBeNull()
        ->and($budget->is_catch_all)->toBeTrue()
        ->and($budget->categories)->toBeEmpty()
        ->and($budget->labels)->toBeEmpty();
});

test('user cannot create a second catch-all budget', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Budget::factory()->catchAll()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->post('/budgets', [
        'name' => 'Another Catch-all',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [],
        'label_ids' => [],
        'rollover_type' => 'reset',
        'allocated_amount' => 50000,
        'is_catch_all' => true,
    ]);

    $response->assertSessionHasErrors('is_catch_all');
    expect(Budget::where('user_id', $user->id)->count())->toBe(1);
});

test('creating a budget rejects categories owned by another user', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $otherUser = User::factory()->create();
    $foreignCategory = Category::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($user)->post('/budgets', [
        'name' => 'Sneaky Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [$foreignCategory->id],
        'rollover_type' => 'reset',
        'allocated_amount' => 50000,
    ]);

    $response->assertSessionHasErrors('category_ids.0');
    expect(Budget::where('user_id', $user->id)->count())->toBe(0);
});

test('budget index hides period_duration and category pivot', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);
    $budget->categories()->attach($category->id);

    $response = $this->actingAs($user)->get(route('budgets.index'));

    $budgetData = $response->viewData('page')['props']['budgets'][0];

    expect($budgetData)->not->toHaveKey('period_duration');
    expect($budgetData['categories'][0])->not->toHaveKey('pivot');
});
