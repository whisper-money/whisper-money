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

function monthlyBudgetStartingOn(int $startDay): Budget
{
    return Budget::factory()->create([
        'user_id' => User::factory()->create(['onboarded_at' => now()])->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => $startDay,
    ]);
}

test('a monthly budget whose start day is 0 always has a period covering today', function (string $today) {
    Carbon::setTestNow(Carbon::parse("{$today} 09:00:00"));

    // Accepted by the validation rules for every period type, because for a
    // weekly budget this column is a day of the week and 0 is Sunday.
    $budget = monthlyBudgetStartingOn(0);

    [$start, $end] = app(BudgetPeriodService::class)
        ->calculatePeriodDates($budget, Carbon::parse($today));

    expect($start->lte(today()))->toBeTrue()
        ->and($end->gte(today()))->toBeTrue();
})->with([
    // The last day of a month, where day(0) resolved to the end of the previous
    // month and the window closed a day early.
    '2026-08-31',
    // Four days at the end of March belonged to no period at all.
    '2026-03-29',
    '2026-03-31',
    // An ordinary day, which was already fine and must stay fine.
    '2026-08-20',
]);

test('a monthly budget still starts on the day it was given', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    // Every other monthly test in the suite uses day 1, so a floor that ignored
    // the budget entirely and returned 1 passed all 76 of them. This is the row
    // that tells the two apart.
    $budget = monthlyBudgetStartingOn(15);

    [$start, $end] = app(BudgetPeriodService::class)
        ->calculatePeriodDates($budget, Carbon::parse('2026-08-20'));

    expect($start->toDateString())->toBe('2026-08-15')
        ->and($end->toDateString())->toBe('2026-09-14');
});

test('a weekly budget still starts on Sunday when its start day is 0', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $budget = Budget::factory()->create([
        'user_id' => User::factory()->create(['onboarded_at' => now()])->id,
        'period_type' => BudgetPeriodType::Weekly,
        'period_start_day' => 0,
    ]);

    [$start, $end] = app(BudgetPeriodService::class)
        ->calculatePeriodDates($budget, Carbon::parse('2026-08-20'));

    // 2026-08-20 is a Thursday; the week it belongs to opened on the Sunday.
    // Locks the floor to the monthly branch: routing the weekly one through it
    // moves this to the Monday.
    expect($start->toDateString())->toBe('2026-08-16')
        ->and($end->toDateString())->toBe('2026-08-22');
});

test('the nightly command gets a frozen day-0 budget moving again', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $budget = monthlyBudgetStartingOn(0);

    // The shape the three production budgets are stuck in: day(0) sends the next
    // start date back to a period that already exists, so `firstOrCreate` keeps
    // handing back the same row and the chain stops dead.
    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-05-31',
        'end_date' => '2026-06-30',
        'allocated_amount' => 10000,
    ]);
    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-06-30',
        'end_date' => '2026-07-29',
        'allocated_amount' => 10000,
    ]);

    $this->artisan('budgets:generate-periods')->assertSuccessful();

    expect($budget->fresh()->getCurrentPeriod())->not->toBeNull();
});
