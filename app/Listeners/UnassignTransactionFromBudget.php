<?php

namespace App\Listeners;

use App\Events\TransactionDeleted;
use App\Services\BudgetTransactionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Laravel\Pennant\Feature;

class UnassignTransactionFromBudget implements ShouldQueue
{
    public function __construct(protected BudgetTransactionService $budgetTransactionService) {}

    public function handle(TransactionDeleted $event): void
    {
        $transaction = $event->transaction;
        $user = $transaction->user;

        if (! $user || ! Feature::for($user)->active('budgets')) {
            return;
        }

        $this->budgetTransactionService->unassignTransaction($transaction);
    }
}
