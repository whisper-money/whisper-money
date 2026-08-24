<?php

use App\Enums\BudgetPeriodType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

function runDeduplicateBudgetTransactionsMigration(): void
{
    $migration = require database_path('migrations/2026_08_21_050014_deduplicate_budget_transactions_from_overlapping_created_periods.php');
    $migration->up();
}

/**
 * The pair budget creation used to produce: two periods a week apart, born in
 * the same call, with the spend in the week they share written against both.
 */
function overlappingPair(Budget $budget, string $createdAt): array
{
    $spurious = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-08-09',
        'end_date' => '2026-08-22',
        'allocated_amount' => 10000,
        'created_at' => $createdAt,
    ]);
    $kept = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-08-16',
        'end_date' => '2026-08-29',
        'allocated_amount' => 10000,
        'created_at' => $createdAt,
    ]);

    return [$spurious, $kept];
}

function biweeklyBudget(): Budget
{
    return Budget::factory()->create([
        'user_id' => User::factory()->create(['onboarded_at' => now()])->id,
        'period_type' => BudgetPeriodType::Biweekly,
        'period_start_day' => 0,
    ]);
}

it('keeps the row on the on-grid period and drops the other', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $budget = biweeklyBudget();
    [$spurious, $kept] = overlappingPair($budget, '2026-08-16 10:00:00');

    $transaction = Transaction::factory()->create([
        'user_id' => $budget->user_id,
        'transaction_date' => '2026-08-18',
        'amount' => -5000,
    ]);
    $droppedRow = BudgetTransaction::factory()->create([
        'transaction_id' => $transaction->id,
        'budget_period_id' => $spurious->id,
        'amount' => 5000,
    ]);
    $keptRow = BudgetTransaction::factory()->create([
        'transaction_id' => $transaction->id,
        'budget_period_id' => $kept->id,
        'amount' => 5000,
    ]);

    runDeduplicateBudgetTransactionsMigration();

    expect(BudgetTransaction::find($droppedRow->id))->toBeNull()
        ->and(BudgetTransaction::find($keptRow->id))->not->toBeNull();
});

it('leaves history the spurious period holds on its own', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $budget = biweeklyBudget();
    [$spurious] = overlappingPair($budget, '2026-08-16 10:00:00');

    // Dated before the budget existed and claimed by nothing else: the backfill
    // picked it up and it is the only record of it.
    $onlyHere = BudgetTransaction::factory()->create([
        'transaction_id' => Transaction::factory()->create([
            'user_id' => $budget->user_id,
            'transaction_date' => '2026-08-10',
            'amount' => -2500,
        ])->id,
        'budget_period_id' => $spurious->id,
        'amount' => 2500,
    ]);

    runDeduplicateBudgetTransactionsMigration();

    expect(BudgetTransaction::find($onlyHere->id))->not->toBeNull();
});

it('does not touch an overlap the periods were not born together in', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $budget = biweeklyBudget();
    $earlier = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-08-09',
        'end_date' => '2026-08-22',
        'allocated_amount' => 10000,
        'created_at' => '2026-07-01 10:00:00',
    ]);
    $later = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-08-16',
        'end_date' => '2026-08-29',
        'allocated_amount' => 10000,
        // A month apart: a genuine mid-chain overlap, where the earlier period
        // is real history rather than this bug's artefact.
        'created_at' => '2026-08-01 10:00:00',
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $budget->user_id,
        'transaction_date' => '2026-08-18',
        'amount' => -5000,
    ]);
    $earlierRow = BudgetTransaction::factory()->create([
        'transaction_id' => $transaction->id,
        'budget_period_id' => $earlier->id,
        'amount' => 5000,
    ]);
    BudgetTransaction::factory()->create([
        'transaction_id' => $transaction->id,
        'budget_period_id' => $later->id,
        'amount' => 5000,
    ]);

    runDeduplicateBudgetTransactionsMigration();

    expect(BudgetTransaction::find($earlierRow->id))->not->toBeNull();
});
