<?php

use App\Enums\BudgetPeriodType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\User;
use App\Services\BudgetPeriodService;
use Carbon\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

test('generatePeriod advances monthly periods to next month', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-02 09:00:00'));

    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
    ]);

    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-31',
        'allocated_amount' => 10000,
    ]);

    $next = app(BudgetPeriodService::class)->generatePeriod($budget);

    expect($next->start_date->toDateString())->toBe('2026-06-01');
    expect($next->end_date->toDateString())->toBe('2026-06-30');
});

test('generatePeriod advances weekly periods to next week', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-12 09:00:00'));

    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Weekly,
        'period_start_day' => 1,
    ]);

    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-05-04',
        'end_date' => '2026-05-10',
        'allocated_amount' => 10000,
    ]);

    $next = app(BudgetPeriodService::class)->generatePeriod($budget);

    expect($next->start_date->toDateString())->toBe('2026-05-11');
    expect($next->end_date->toDateString())->toBe('2026-05-17');
});

test('generatePeriod advances yearly periods to next year', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-02 09:00:00'));

    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Yearly,
        'period_start_day' => 1,
    ]);

    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2025-01-01',
        'end_date' => '2025-12-31',
        'allocated_amount' => 10000,
    ]);

    $next = app(BudgetPeriodService::class)->generatePeriod($budget);

    expect($next->start_date->toDateString())->toBe('2026-01-01');
    expect($next->end_date->toDateString())->toBe('2026-12-31');
});

test('generatePeriod uses period_start_day snap when no prior periods exist', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-15 09:00:00'));

    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
    ]);

    $period = app(BudgetPeriodService::class)->generatePeriod($budget);

    expect($period->start_date->toDateString())->toBe('2026-05-01');
    expect($period->end_date->toDateString())->toBe('2026-05-31');
});

test('generatePeriod is idempotent when a period already exists for the start date', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));

    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
    ]);

    $existing = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
        'allocated_amount' => 44500,
    ]);

    $period = app(BudgetPeriodService::class)->generatePeriod($budget, 100, Carbon::parse('2026-06-15'));

    expect($period->id)->toBe($existing->id);
    expect($period->allocated_amount)->toBe(44500);
    expect(BudgetPeriod::where('budget_id', $budget->id)->count())->toBe(1);
});

test('generatePeriod creates current calendar year when yearly budget has no prior periods', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-15 09:00:00'));

    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Yearly,
        'period_start_day' => 1,
    ]);

    $period = app(BudgetPeriodService::class)->generatePeriod($budget);

    expect($period->start_date->toDateString())->toBe('2026-01-01');
    expect($period->end_date->toDateString())->toBe('2026-12-31');
});
