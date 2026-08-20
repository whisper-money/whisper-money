<?php

use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
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

test('closePeriod carries the leftover into the period that already follows', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
        'rollover_type' => RolloverType::CarryOver,
    ]);

    $july = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'allocated_amount' => 10000,
    ]);
    $august = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'allocated_amount' => 10000,
    ]);
    // The one the command keeps ahead. It is here so that "the period that
    // follows" cannot pass by accident: with a single successor, taking the
    // nearest and taking the farthest are the same row.
    $september = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'allocated_amount' => 10000,
    ]);

    BudgetTransaction::factory()->create([
        'budget_period_id' => $july->id,
        'amount' => 4000,
    ]);

    app(BudgetPeriodService::class)->closePeriod($july->fresh());

    // The leftover belongs on the period the user is spending in now, not on a
    // fresh one appended past the end of the chain and not on the one after it.
    expect($august->fresh()->carried_over_amount)->toBe(6000)
        ->and($september->fresh()->carried_over_amount)->toBe(0)
        ->and(BudgetPeriod::where('budget_id', $budget->id)->count())->toBe(3);
});

test('closePeriod never carries into another budget', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $budget = Budget::factory()->create([
        'user_id' => User::factory()->create(['onboarded_at' => now()])->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
        'rollover_type' => RolloverType::CarryOver,
    ]);
    $july = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'allocated_amount' => 10000,
    ]);

    // Nothing follows July on this budget, so the only period that follows
    // July anywhere in the table belongs to somebody else.
    $otherBudget = Budget::factory()->create([
        'user_id' => User::factory()->create(['onboarded_at' => now()])->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
        'rollover_type' => RolloverType::CarryOver,
    ]);
    $someoneElses = BudgetPeriod::factory()->create([
        'budget_id' => $otherBudget->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'allocated_amount' => 10000,
    ]);

    BudgetTransaction::factory()->create([
        'budget_period_id' => $july->id,
        'amount' => 4000,
    ]);

    app(BudgetPeriodService::class)->closePeriod($july->fresh());

    expect($someoneElses->fresh()->carried_over_amount)->toBe(0)
        ->and(BudgetPeriod::where('budget_id', $budget->id)->count())->toBe(2);
});

test('closePeriod leaves nothing behind for a budget that resets each period', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
        'rollover_type' => RolloverType::Reset,
    ]);

    $july = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'allocated_amount' => 10000,
    ]);
    $august = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'allocated_amount' => 10000,
    ]);

    app(BudgetPeriodService::class)->closePeriod($july);

    expect($august->fresh()->carried_over_amount)->toBe(0);
});

test('closePeriod stops appending periods when the same period is closed again', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
        'rollover_type' => RolloverType::CarryOver,
    ]);

    $july = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'allocated_amount' => 10000,
    ]);
    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'allocated_amount' => 10000,
    ]);

    $service = app(BudgetPeriodService::class);
    $service->closePeriod($july);
    $service->closePeriod($july);
    $service->closePeriod($july);

    expect(BudgetPeriod::where('budget_id', $budget->id)->count())->toBe(2);
});

test('closePeriod builds the missing successor next to the closed period', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
        'rollover_type' => RolloverType::CarryOver,
    ]);

    $july = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'allocated_amount' => 10000,
    ]);

    app(BudgetPeriodService::class)->closePeriod($july);

    $created = BudgetPeriod::where('budget_id', $budget->id)
        ->where('start_date', '>', $july->end_date)
        ->sole();

    expect($created->start_date->toDateString())->toBe('2026-08-01');
});

test('closePeriod finds a successor that starts on the closed period last day', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $budget = Budget::factory()->create([
        'user_id' => User::factory()->create(['onboarded_at' => now()])->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 0,
        'rollover_type' => RolloverType::CarryOver,
    ]);

    // Periods that touch rather than tile. 25 live budgets look like this - the
    // ones whose start day is 0 or past the 28th, where `calculatePeriodDates`
    // resolves the day to the end of the previous month.
    $ended = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-06-30',
        'end_date' => '2026-07-30',
        'allocated_amount' => 10000,
    ]);
    $current = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-07-30',
        'end_date' => '2026-08-30',
        'allocated_amount' => 10000,
    ]);

    BudgetTransaction::factory()->create([
        'budget_period_id' => $ended->id,
        'amount' => 4000,
    ]);

    app(BudgetPeriodService::class)->closePeriod($ended->fresh());

    // Skipping that shared day used to send the fallback back through
    // `calculatePeriodDates`, which resolved to the closed period itself - so
    // the leftover was carried onto the period it came from.
    expect($current->fresh()->carried_over_amount)->toBe(6000)
        ->and($ended->fresh()->carried_over_amount)->toBe(0)
        ->and(BudgetPeriod::where('budget_id', $budget->id)->count())->toBe(2);
});
