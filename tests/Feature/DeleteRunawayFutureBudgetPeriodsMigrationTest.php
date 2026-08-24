<?php

use App\Enums\BudgetPeriodType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\User;
use Carbon\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

function runDeleteRunawayFutureBudgetPeriodsMigration(): void
{
    $migration = require database_path('migrations/2026_08_20_104233_delete_runaway_future_budget_periods.php');
    $migration->up();
}

function budgetUnderRepair(): Budget
{
    $user = User::factory()->create(['onboarded_at' => now()]);

    return Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
    ]);
}

function periodUnderRepair(Budget $budget, string $start, string $end): BudgetPeriod
{
    return BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => $start,
        'end_date' => $end,
        'allocated_amount' => 10000,
    ]);
}

it('keeps the past, the present and two periods ahead', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $budget = budgetUnderRepair();
    $past = periodUnderRepair($budget, '2026-07-01', '2026-07-31');
    $current = periodUnderRepair($budget, '2026-08-01', '2026-08-31');
    $firstAhead = periodUnderRepair($budget, '2026-09-01', '2026-09-30');
    $secondAhead = periodUnderRepair($budget, '2026-10-01', '2026-10-31');
    $runaway = periodUnderRepair($budget, '2026-11-01', '2026-11-30');
    $absurd = periodUnderRepair($budget, '2143-06-01', '2143-06-30');

    runDeleteRunawayFutureBudgetPeriodsMigration();

    expect(BudgetPeriod::find($past->id))->not->toBeNull()
        ->and(BudgetPeriod::find($current->id))->not->toBeNull()
        ->and(BudgetPeriod::find($firstAhead->id))->not->toBeNull()
        ->and(BudgetPeriod::find($secondAhead->id))->not->toBeNull()
        ->and(BudgetPeriod::find($runaway->id))->toBeNull()
        ->and(BudgetPeriod::find($absurd->id))->toBeNull();
});

it('leaves a future period alone when transactions hang off it', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $budget = budgetUnderRepair();
    periodUnderRepair($budget, '2026-09-01', '2026-09-30');
    periodUnderRepair($budget, '2026-10-01', '2026-10-31');
    $spentAhead = periodUnderRepair($budget, '2026-11-01', '2026-11-30');

    BudgetTransaction::factory()->create([
        'budget_period_id' => $spentAhead->id,
        'amount' => 2500,
    ]);

    runDeleteRunawayFutureBudgetPeriodsMigration();

    expect(BudgetPeriod::find($spentAhead->id))->not->toBeNull();
});

it('does not reach across budgets', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $mine = budgetUnderRepair();
    $theirs = budgetUnderRepair();

    periodUnderRepair($mine, '2026-09-01', '2026-09-30');
    periodUnderRepair($mine, '2026-10-01', '2026-10-31');
    periodUnderRepair($mine, '2026-11-01', '2026-11-30');
    $theirFirst = periodUnderRepair($theirs, '2026-09-01', '2026-09-30');
    $theirSecond = periodUnderRepair($theirs, '2026-10-01', '2026-10-31');

    runDeleteRunawayFutureBudgetPeriodsMigration();

    expect(BudgetPeriod::where('budget_id', $mine->id)->count())->toBe(2)
        ->and(BudgetPeriod::find($theirFirst->id))->not->toBeNull()
        ->and(BudgetPeriod::find($theirSecond->id))->not->toBeNull();
});

it('clears the invented carry-over from the periods it keeps', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $budget = budgetUnderRepair();
    $current = periodUnderRepair($budget, '2026-08-01', '2026-08-31');
    $current->update(['carried_over_amount' => 750]);
    $kept = periodUnderRepair($budget, '2026-09-01', '2026-09-30');
    $kept->update(['carried_over_amount' => 999999999991725246]);

    runDeleteRunawayFutureBudgetPeriodsMigration();

    expect($kept->fresh()->carried_over_amount)->toBe(0)
        // Whatever the past and the present hold was earned, not invented.
        ->and($current->fresh()->carried_over_amount)->toBe(750);
});
