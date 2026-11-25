<?php

namespace App\Listeners;

use App\Events\TransactionCreated;
use App\Events\TransactionUpdated;
use App\Services\BudgetTransactionService;
use Illuminate\Contracts\Queue\ShouldQueue;

class AssignTransactionToBudget implements ShouldQueue
{
    public function __construct(protected BudgetTransactionService $budgetTransactionService) {}

    /**
     * A full import assigns the rows it writes in one batch per chunk
     * (ReassignTransactionsToBudgets, without budget emails), instead of
     * queueing a job for each of thousands of rows. Only its creation is
     * skipped: a later edit of an imported row is assigned like any other.
     *
     * The same goes for one part of a split: it is saved before its labels
     * are, so TransactionSplitter assigns every part once the split has
     * committed (TransactionSplitter::split()).
     */
    public function shouldQueue(TransactionCreated|TransactionUpdated $event): bool
    {
        return ! ($event instanceof TransactionCreated
            && ($event->transaction->import_id !== null || $event->transaction->isSplitPart()));
    }

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
