<?php

use App\Enums\BudgetPeriodType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\User;
use Carbon\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

function runRealignMonthlyBudgetsMigration(): void
{
    $migration = require database_path('migrations/2026_08_20_132408_realign_monthly_budgets_stored_with_day_zero.php');
    $migration->up();
}

function budgetStartingOn(BudgetPeriodType $type, int $startDay): Budget
{
    return Budget::factory()->create([
        'user_id' => User::factory()->create(['onboarded_at' => now()])->id,
        'period_type' => $type,
        'period_start_day' => $startDay,
    ]);
}

/** The chain the three production budgets are frozen in. */
function frozenChain(Budget $budget): BudgetPeriod
{
    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-05-31',
        'end_date' => '2026-06-30',
        'allocated_amount' => 10000,
    ]);

    return BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-06-30',
        'end_date' => '2026-07-29',
        'allocated_amount' => 10000,
    ]);
}

it('closes the last period at the end of its month and normalises the stored day', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $budget = budgetStartingOn(BudgetPeriodType::Monthly, 0);
    $lastPeriod = frozenChain($budget);

    runRealignMonthlyBudgetsMigration();

    expect($lastPeriod->fresh()->end_date->toDateString())->toBe('2026-07-31')
        ->and($budget->fresh()->period_start_day)->toBe(1);
});

it('leaves the chain contiguous once the nightly command runs', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $budget = budgetStartingOn(BudgetPeriodType::Monthly, 0);
    frozenChain($budget);

    runRealignMonthlyBudgetsMigration();

    $this->artisan('budgets:generate-periods')->assertSuccessful();

    $periods = BudgetPeriod::where('budget_id', $budget->id)
        ->orderBy('start_date')
        ->get();

    // Without the repair the command adds a second July, overlapping the old one
    // by 29 days and stranding the period that holds the transactions. The two
    // historical rows are left exactly as they are - they overlap each other by a
    // day, which is what a day-0 chain looks like, and rewriting history would
    // re-bucket real transactions.
    $added = $periods->filter(fn (BudgetPeriod $period) => $period->start_date->gt(Carbon::parse('2026-07-31')));

    expect($periods)->toHaveCount(2 + $added->count())
        ->and($added->first()->start_date->toDateString())->toBe('2026-08-01')
        ->and($budget->fresh()->getCurrentPeriod())->not->toBeNull();
});

it('does not touch a monthly budget with a real start day', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $budget = budgetStartingOn(BudgetPeriodType::Monthly, 15);
    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-07-15',
        'end_date' => '2026-08-14',
        'allocated_amount' => 10000,
    ]);

    runRealignMonthlyBudgetsMigration();

    expect($period->fresh()->end_date->toDateString())->toBe('2026-08-14')
        ->and($budget->fresh()->period_start_day)->toBe(15);
});

it('leaves a weekly budget starting on Sunday alone', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    // 0 means Sunday here, and it is the correct value.
    $budget = budgetStartingOn(BudgetPeriodType::Weekly, 0);
    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-08-16',
        'end_date' => '2026-08-22',
        'allocated_amount' => 10000,
    ]);

    runRealignMonthlyBudgetsMigration();

    expect($period->fresh()->end_date->toDateString())->toBe('2026-08-22')
        ->and($budget->fresh()->period_start_day)->toBe(0);
});
