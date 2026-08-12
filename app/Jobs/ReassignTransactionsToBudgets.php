<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\BudgetTransactionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-derive budget membership for transactions whose category or labels changed
 * without a model event.
 *
 * Pivot writes and mass updates fire nothing, so `AssignTransactionToBudget`
 * never runs for them and the transaction stays in whatever budget it was in —
 * typically the catch-all, even once a budget tracks its new label. This job
 * stands in for the missing event without re-broadcasting `TransactionUpdated`,
 * which would also re-run the automation rules that dispatched it.
 */
class ReassignTransactionsToBudgets implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $transactionIds
     * @param  bool  $notify  set to false for bulk applies, where the budget
     *                        emails would describe limits crossed long ago
     */
    public function __construct(
        public array $transactionIds,
        public bool $notify = true,
    ) {}

    public function handle(BudgetTransactionService $service): void
    {
        Transaction::query()
            ->whereIn('id', $this->transactionIds)
            ->with('labels')
            ->chunkById(200, function (Collection $transactions) use ($service): void {
                foreach ($transactions as $transaction) {
                    $service->assignTransaction($transaction, notify: $this->notify);
                }
            });
    }
}
