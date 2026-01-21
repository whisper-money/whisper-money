<?php

namespace App\Listeners;

use App\Events\TransactionCreated;
use App\Events\TransactionUpdated;
use App\Services\BudgetTransactionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Laravel\Pennant\Feature;

class AssignTransactionToBudget implements ShouldQueue
{
    public function __construct(protected BudgetTransactionService $budgetTransactionService) {}

    public function handle(TransactionCreated|TransactionUpdated $event): void
    {
        $transaction = $event->transaction;
        $user = $transaction->user;

        if (! $user || ! Feature::for($user)->active('budgets')) {
            return;
        }

        $this->budgetTransactionService->assignTransaction($transaction);
    }
}
