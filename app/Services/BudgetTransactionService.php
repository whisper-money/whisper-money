<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Transaction;

class BudgetTransactionService
{
    public function assignTransaction(Transaction $transaction): void
    {
        // Remove any existing assignments
        $this->unassignTransaction($transaction);

        // Find matching budget periods
        $budgetPeriods = BudgetPeriod::query()
            ->whereHas('budget', function ($query) use ($transaction) {
                $query->where(function ($q) use ($transaction) {
                    // Match by category
                    $q->where('category_id', $transaction->category_id);
                })
                ->orWhere(function ($q) use ($transaction) {
                    // Match by label
                    $q->whereHas('label', function ($labelQuery) use ($transaction) {
                        $labelQuery->whereIn('id', $transaction->labels->pluck('id'));
                    });
                });
            })
            ->where('start_date', '<=', $transaction->transaction_date)
            ->where('end_date', '>=', $transaction->transaction_date)
            ->with('budget')
            ->get();

        foreach ($budgetPeriods as $period) {
            $budget = $period->budget;
            
            // Check if transaction matches budget criteria
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
                    'amount' => abs($transaction->amount),
                ]);
            }
        }
    }

    public function unassignTransaction(Transaction $transaction): void
    {
        BudgetTransaction::where('transaction_id', $transaction->id)->delete();
    }
}
