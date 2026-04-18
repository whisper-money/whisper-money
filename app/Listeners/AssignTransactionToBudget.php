<?php

namespace App\Listeners;

use App\Events\TransactionCreated;
use App\Events\TransactionUpdated;
use App\Services\BudgetTransactionService;
use Illuminate\Contracts\Queue\ShouldQueue;

class AssignTransactionToBudget implements ShouldQueue
{
    public function __construct(protected BudgetTransactionService $budgetTransactionService) {}

    public function handle(TransactionCreated|TransactionUpdated $event): void
    {
        $transaction = $event->transaction;

        if (! $transaction->user) {
            return;
        }

        // Ensure labels are loaded fresh (they're not preserved during queue serialization)
        $transaction->load('labels');

        $this->budgetTransactionService->assignTransaction($transaction);
    }
}
