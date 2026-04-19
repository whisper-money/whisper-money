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

        // Find budget periods that potentially match this transaction.
        $budgetPeriods = BudgetPeriod::query()
            ->whereHas('budget', function ($query) use ($transaction, $userId) {
                $query->where('user_id', $userId)
                    ->where(function ($q) use ($transaction) {
                        $q->where('category_id', $transaction->category_id)
                            ->orWhere(function ($labelQuery) use ($transaction) {
                                $labelQuery->whereHas('label', function ($lq) use ($transaction) {
                                    $lq->whereIn('id', $transaction->labels->pluck('id'));
                                });
                            });
                    });
            })
            ->where('start_date', '<=', $transaction->transaction_date)
            ->where('end_date', '>=', $transaction->transaction_date)
            ->with('budget')
            ->get();

        // Narrow down to periods whose budget actually matches the transaction.
        $matchingPeriodIds = [];

        foreach ($budgetPeriods as $period) {
            $budget = $period->budget;

            $matchesCategory = $budget->category_id && $budget->category_id === $transaction->category_id;
            $matchesLabel = $budget->label_id && $transaction->labels->contains('id', $budget->label_id);

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
        $budget = $period->budget()->with(['category', 'label'])->first();

        if (! $budget) {
            return 0;
        }

        $assignedCount = 0;

        Log::info('Building query for historical transactions', [
            'user_id' => $budget->user_id,
            'category_id' => $budget->category_id,
            'label_id' => $budget->label_id,
            'start_date' => $period->start_date->toDateString(),
            'end_date' => $period->end_date->toDateString(),
        ]);

        // Build the query for matching transactions
        $query = Transaction::query()
            ->where('user_id', $budget->user_id)
            ->whereBetween('transaction_date', [$period->start_date, $period->end_date])
            ->withoutTrashed();

        // Filter by category OR label
        $query->where(function ($q) use ($budget) {
            if ($budget->category_id) {
                $q->where('category_id', $budget->category_id);
            }

            if ($budget->label_id) {
                $q->orWhereHas('labels', function ($labelQuery) use ($budget) {
                    $labelQuery->where('labels.id', $budget->label_id);
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
