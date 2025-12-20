<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Services\BudgetTransactionService;

class TransactionObserver
{
    public function __construct(protected BudgetTransactionService $budgetTransactionService) {}

    public function created(Transaction $transaction): void
    {
        $this->budgetTransactionService->assignTransaction($transaction);
    }

    public function updated(Transaction $transaction): void
    {
        $this->budgetTransactionService->assignTransaction($transaction);
    }

    public function deleted(Transaction $transaction): void
    {
        $this->budgetTransactionService->unassignTransaction($transaction);
    }

    public function restored(Transaction $transaction): void
    {
        $this->budgetTransactionService->assignTransaction($transaction);
    }

    public function forceDeleted(Transaction $transaction): void
    {
        $this->budgetTransactionService->unassignTransaction($transaction);
    }
}
