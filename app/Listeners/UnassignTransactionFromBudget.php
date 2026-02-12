<?php

namespace App\Listeners;

use App\Events\TransactionDeleted;
use App\Services\BudgetTransactionService;
use Illuminate\Contracts\Queue\ShouldQueue;

class UnassignTransactionFromBudget implements ShouldQueue
{
    public function __construct(protected BudgetTransactionService $budgetTransactionService) {}

    public function handle(TransactionDeleted $event): void
    {
        $transaction = $event->transaction;

        if (! $transaction->user) {
            return;
        }

        $this->budgetTransactionService->unassignTransaction($transaction);
    }
}
