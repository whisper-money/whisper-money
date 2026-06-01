<?php

namespace App\Services;

use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BudgetTransactionService
{
    public function assignTransaction(Transaction $transaction): void
    {
        $userId = $transaction->user_id;

        if (! $userId) {
            return;
        }

        // Ensure labels are available for matching (safe if already loaded).
        $transaction->loadMissing('labels');

        $transactionLabelIds = $transaction->labels->pluck('id');

        // Find budget periods that potentially match this transaction.
        $budgetPeriods = BudgetPeriod::query()
            ->whereHas('budget', function ($query) use ($transaction, $transactionLabelIds, $userId) {
                $query->where('user_id', $userId)
                    ->where(function ($q) use ($transaction, $transactionLabelIds) {
                        $q->whereHas('categories', function ($cq) use ($transaction) {
                            $cq->whereKey($transaction->category_id);
                        })
                            ->orWhereHas('labels', function ($lq) use ($transactionLabelIds) {
                                $lq->whereIn('labels.id', $transactionLabelIds);
                            });
                    });
            })
            ->where('start_date', '<=', $transaction->transaction_date)
            ->where('end_date', '>=', $transaction->transaction_date)
            ->with('budget.categories:id', 'budget.labels:id')
            ->get();

        // Narrow down to periods whose budget actually matches the transaction.
        $matchingPeriodIds = [];

        foreach ($budgetPeriods as $period) {
            $budget = $period->budget;

            $matchesCategory = $transaction->category_id
                && $budget->categories->contains('id', $transaction->category_id);
            $matchesLabel = $budget->labels
                ->pluck('id')
                ->intersect($transactionLabelIds)
                ->isNotEmpty();

            if ($matchesCategory || $matchesLabel) {
                $matchingPeriodIds[] = $period->id;
            }
        }

        // Apply changes atomically so concurrent workers cannot leave the
        // transaction half-assigned and the unique index guards duplicates.
        DB::transaction(function () use ($transaction, $matchingPeriodIds) {
            Transaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->first();

            BudgetTransaction::query()
                ->where('transaction_id', $transaction->id)
                ->when(
                    $matchingPeriodIds !== [],
                    fn ($q) => $q->whereNotIn('budget_period_id', $matchingPeriodIds),
                )
                ->delete();

            foreach ($matchingPeriodIds as $periodId) {
                BudgetTransaction::updateOrCreate(
                    [
                        'transaction_id' => $transaction->id,
                        'budget_period_id' => $periodId,
                    ],
                    [
                        'amount' => -$transaction->amount,
                    ],
                );
            }
        }, attempts: 5);
    }

    public function unassignTransaction(Transaction $transaction): void
    {
        BudgetTransaction::where('transaction_id', $transaction->id)->delete();
    }

    public function assignHistoricalTransactionsToPeriod(BudgetPeriod $period): int
    {
        // Load the budget with its relationships
        $budget = $period->budget()->with(['categories:id', 'labels:id'])->first();

        if (! $budget) {
            return 0;
        }

        $assignedCount = 0;

        $categoryIds = $budget->categories->pluck('id');
        $labelIds = $budget->labels->pluck('id');

        Log::info('Building query for historical transactions', [
            'user_id' => $budget->user_id,
            'category_ids' => $categoryIds->all(),
            'label_ids' => $labelIds->all(),
            'start_date' => $period->start_date->toDateString(),
            'end_date' => $period->end_date->toDateString(),
        ]);

        // Build the query for matching transactions
        $query = Transaction::query()
            ->where('user_id', $budget->user_id)
            ->whereBetween('transaction_date', [$period->start_date, $period->end_date])
            ->withoutTrashed();

        // Filter by any tracked category OR label
        $query->where(function ($q) use ($categoryIds, $labelIds) {
            if ($categoryIds->isNotEmpty()) {
                $q->whereIn('category_id', $categoryIds);
            }

            if ($labelIds->isNotEmpty()) {
                $q->orWhereHas('labels', function ($labelQuery) use ($labelIds) {
                    $labelQuery->whereIn('labels.id', $labelIds);
                });
            }
        });

        $totalCount = $query->count();
        Log::info("Found {$totalCount} transactions to process in date range");

        // Process in chunks to prevent memory issues
        $query->chunk(500, function ($transactions) use ($period, &$assignedCount) {
            foreach ($transactions as $transaction) {
                $budgetTransaction = BudgetTransaction::updateOrCreate(
                    [
                        'transaction_id' => $transaction->id,
                        'budget_period_id' => $period->id,
                    ],
                    [
                        'amount' => -$transaction->amount,
                    ],
                );

                if ($budgetTransaction->wasRecentlyCreated) {
                    $assignedCount++;
                }
            }
        });

        return $assignedCount;
    }
}
