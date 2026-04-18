<?php

namespace App\Listeners;

use App\Events\TransactionCreated;
use App\Events\TransactionUpdated;
use App\Services\BudgetTransactionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

class AssignTransactionToBudget implements ShouldBeUnique, ShouldQueue
{
    /**
     * Transaction attributes that affect budget assignment. When a
     * TransactionUpdated event fires and none of these changed, we skip.
     *
     * @var list<string>
     */
    private const BUDGET_RELEVANT_ATTRIBUTES = [
        'user_id',
        'category_id',
        'amount',
        'transaction_date',
    ];

    /**
     * Seconds to hold the uniqueness lock so that rapidly fired
     * TransactionCreated + TransactionUpdated events collapse into a
     * single queued job for the same transaction.
     */
    public int $uniqueFor = 30;

    public function __construct(protected BudgetTransactionService $budgetTransactionService) {}

    public function uniqueId(TransactionCreated|TransactionUpdated $event): string
    {
        return $event->transaction->id;
    }

    public function handle(TransactionCreated|TransactionUpdated $event): void
    {
        $transaction = $event->transaction;

        if (! $transaction->user) {
            return;
        }

        if ($event instanceof TransactionUpdated
            && ! $event->changedAny(self::BUDGET_RELEVANT_ATTRIBUTES)) {
            return;
        }

        // Ensure labels are loaded fresh (they're not preserved during queue serialization)
        $transaction->load('labels');

        $this->budgetTransactionService->assignTransaction($transaction);
    }
}
