<?php

namespace App\Listeners;

use App\Events\TransactionDeleted;
use App\Models\Transaction;
use App\Services\BudgetTransactionService;
use Illuminate\Contracts\Queue\ShouldQueue;

class UnassignTransactionFromBudget implements ShouldQueue
{
    public function __construct(protected BudgetTransactionService $budgetTransactionService) {}

    /**
     * A row that is live again by the time this runs keeps its budgets: a
     * split merged back quickly restores its original, and with more than one
     * worker the original's own unassignment, queued by the split, can run
     * after the merge has put it back.
     */
    public function handle(TransactionDeleted $event): void
    {
        $transaction = $event->transaction;

        if (! $transaction->user || Transaction::query()->whereKey($transaction->id)->exists()) {
            return;
        }

        $this->budgetTransactionService->unassignTransaction($transaction);
    }
}
