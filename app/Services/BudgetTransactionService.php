<?php

namespace App\Services;

use App\Models\BudgetPeriodAllocation;
use App\Models\BudgetTransaction;
use App\Models\Transaction;

class BudgetTransactionService
{
    public function assignTransaction(Transaction $transaction): void
    {
        $this->unassignTransaction($transaction);

        if (! $transaction->category_id) {
            return;
        }

        $allocations = BudgetPeriodAllocation::query()
            ->whereHas('budgetPeriod', function ($query) use ($transaction) {
                $query->where('start_date', '<=', $transaction->transaction_date)
                    ->where('end_date', '>=', $transaction->transaction_date);
            })
            ->whereHas('budgetCategory', function ($query) use ($transaction) {
                $query->where('category_id', $transaction->category_id);
            })
            ->get();

        if ($allocations->isEmpty()) {
            return;
        }

        $transactionLabels = $transaction->labels->pluck('id')->toArray();

        foreach ($allocations as $allocation) {
            $budgetCategory = $allocation->budgetCategory;
            $budgetLabels = $budgetCategory->labels->pluck('id')->toArray();

            if (empty($budgetLabels)) {
                $this->createBudgetTransaction($transaction, $allocation);
                break;
            }

            if (! empty(array_intersect($transactionLabels, $budgetLabels))) {
                $this->createBudgetTransaction($transaction, $allocation);
                break;
            }
        }
    }

    public function manualAssign(Transaction $transaction, BudgetPeriodAllocation $allocation, int $amount): BudgetTransaction
    {
        $this->unassignTransaction($transaction);

        return BudgetTransaction::create([
            'transaction_id' => $transaction->id,
            'budget_period_allocation_id' => $allocation->id,
            'amount' => abs($amount),
        ]);
    }

    public function unassignTransaction(Transaction $transaction): void
    {
        BudgetTransaction::where('transaction_id', $transaction->id)->delete();
    }

    protected function createBudgetTransaction(Transaction $transaction, BudgetPeriodAllocation $allocation): BudgetTransaction
    {
        return BudgetTransaction::create([
            'transaction_id' => $transaction->id,
            'budget_period_allocation_id' => $allocation->id,
            'amount' => abs($transaction->amount),
        ]);
    }
}

