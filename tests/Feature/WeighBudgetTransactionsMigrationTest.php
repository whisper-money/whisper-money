<?php

use App\Models\Account;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Transaction;
use App\Models\User;

function runWeighBudgetTransactionsMigration(): void
{
    $migration = require database_path('migrations/2026_08_12_100000_weigh_budget_transactions_by_account_ownership.php');
    $migration->up();
}

/**
 * A budget row holding the full transaction amount, the way every row written
 * before the ownership weighting looks.
 */
function unweighedBudgetRow(User $user, int $percentage, int $amount): BudgetTransaction
{
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'ownership_percentage' => $percentage,
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => $amount,
    ]);

    return BudgetTransaction::factory()->create([
        'transaction_id' => $transaction->id,
        'budget_period_id' => BudgetPeriod::factory()->create()->id,
        'amount' => -$amount,
    ]);
}

it('re-weighs only the rows of shared accounts', function () {
    $user = User::factory()->create();

    $shared = unweighedBudgetRow($user, 50, -80000);
    $refundOnShared = unweighedBudgetRow($user, 50, 20000);
    $fullyOwned = unweighedBudgetRow($user, 100, -80000);

    runWeighBudgetTransactionsMigration();

    expect($shared->fresh()->amount)->toBe(40000)
        // A refund stays negative, keeping the fix from the 2026_02_24 migration.
        ->and($refundOnShared->fresh()->amount)->toBe(-10000)
        ->and($fullyOwned->fresh()->amount)->toBe(80000);
});

it('is idempotent, so a second run does not halve the amounts again', function () {
    $user = User::factory()->create();
    $shared = unweighedBudgetRow($user, 50, -80000);

    runWeighBudgetTransactionsMigration();
    runWeighBudgetTransactionsMigration();

    expect($shared->fresh()->amount)->toBe(40000);
});
