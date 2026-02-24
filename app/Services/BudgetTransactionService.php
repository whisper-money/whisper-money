<?php

namespace App\Services;

use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class BudgetTransactionService
{
    public function assignTransaction(Transaction $transaction): void
    {
        // Remove any existing assignments first
        if ($transaction->budgetTransactions()->exists()) {
            $this->unassignTransaction($transaction);
        }

        // Get the user who owns this transaction
        $userId = $transaction->user_id;

        if (! $userId) {
            return;
        }

        // Find matching budget periods for this user only
        $budgetPeriods = BudgetPeriod::query()
            ->whereHas('budget', function ($query) use ($transaction, $userId) {
                // Scope to user's budgets only
                $query->where('user_id', $userId)
                    ->where(function ($q) use ($transaction) {
                        // Match by category
                        $q->where('category_id', $transaction->category_id)
                            ->orWhere(function ($labelQuery) use ($transaction) {
                                // Match by label
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

        foreach ($budgetPeriods as $period) {
            $budget = $period->budget;

            // Double-check transaction matches budget criteria
            $matches = false;

            if ($budget->category_id && $budget->category_id === $transaction->category_id) {
                $matches = true;
            }

            if ($budget->label_id && $transaction->labels->contains('id', $budget->label_id)) {
                $matches = true;
            }

            if ($matches) {
                BudgetTransaction::create([
                    'transaction_id' => $transaction->id,
                    'budget_period_id' => $period->id,
                    'amount' => -$transaction->amount,
                ]);
            }
        }
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
                // Check if assignment already exists (prevent duplicates)
                $exists = BudgetTransaction::where('transaction_id', $transaction->id)
                    ->where('budget_period_id', $period->id)
                    ->exists();

                if (! $exists) {
                    BudgetTransaction::create([
                        'transaction_id' => $transaction->id,
                        'budget_period_id' => $period->id,
                        'amount' => -$transaction->amount,
                    ]);

                    $assignedCount++;
                }
            }
        });

        return $assignedCount;
    }
}
