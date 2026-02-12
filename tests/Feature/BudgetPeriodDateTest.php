<?php

use App\Enums\BudgetPeriodType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\User;
use Carbon\Carbon;

test('getCurrentPeriod finds period on its last day regardless of time', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-31 15:30:00'));

    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
    ]);

    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'allocated_amount' => 100000,
    ]);

    $currentPeriod = $budget->getCurrentPeriod();

    expect($currentPeriod)->not->toBeNull();
    expect($currentPeriod->start_date->toDateString())->toBe('2026-01-01');
    expect($currentPeriod->end_date->toDateString())->toBe('2026-01-31');

    Carbon::setTestNow();
});

test('getCurrentPeriod finds period on its first day regardless of time', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-01 23:59:59'));

    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
    ]);

    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'allocated_amount' => 100000,
    ]);

    $currentPeriod = $budget->getCurrentPeriod();

    expect($currentPeriod)->not->toBeNull();
    expect($currentPeriod->start_date->toDateString())->toBe('2026-01-01');

    Carbon::setTestNow();
});

test('budget index loads current period on last day of period', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-31 18:00:00'));

    $user = User::factory()->create(['onboarded_at' => now()]);

    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
    ]);

    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'allocated_amount' => 100000,
    ]);

    $response = $this->actingAs($user)->get('/budgets');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('budgets/index')
        ->has('budgets', 1)
        ->where('budgets.0.periods', fn ($periods) => count($periods) === 1)
    );

    Carbon::setTestNow();
});

test('budget show finds current period on last day of period', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-31 20:00:00'));

    $user = User::factory()->create(['onboarded_at' => now()]);

    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
    ]);

    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'allocated_amount' => 100000,
    ]);

    $response = $this->actingAs($user)->get("/budgets/{$budget->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('budgets/show')
        ->has('currentPeriod')
        ->where('currentPeriod.start_date', '2026-01-01T00:00:00.000000Z')
    );

    Carbon::setTestNow();
});
