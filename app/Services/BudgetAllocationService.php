<?php

namespace App\Services;

use App\Enums\CategoryType;
use App\Models\BudgetCategory;
use App\Models\BudgetPeriod;
use App\Models\BudgetPeriodAllocation;

class BudgetAllocationService
{
    public function allocate(BudgetPeriod $period, BudgetCategory $budgetCategory, int $amount): BudgetPeriodAllocation
    {
        return BudgetPeriodAllocation::updateOrCreate(
            [
                'budget_period_id' => $period->id,
                'budget_category_id' => $budgetCategory->id,
            ],
            [
                'allocated_amount' => $amount,
            ]
        );
    }

    public function calculateAvailableToAssign(BudgetPeriod $period): int
    {
        $totalIncome = $this->getIncomeForPeriod($period);
        $totalAllocated = $period->allocations()->sum('allocated_amount');
        $carriedOver = $period->carried_over_amount;

        return $totalIncome + $carriedOver - $totalAllocated;
    }

    public function getIncomeForPeriod(BudgetPeriod $period): int
    {
        $budget = $period->budget;
        $user = $budget->user;

        return $user->transactions()
            ->whereHas('category', function ($query) {
                $query->where('type', CategoryType::Income);
            })
            ->whereBetween('transaction_date', [$period->start_date, $period->end_date])
            ->sum('amount');
    }

    public function bulkAllocate(BudgetPeriod $period, array $allocations): void
    {
        foreach ($allocations as $allocationData) {
            $budgetCategory = BudgetCategory::find($allocationData['budget_category_id']);
            if ($budgetCategory && $budgetCategory->budget_id === $period->budget_id) {
                $this->allocate($period, $budgetCategory, $allocationData['allocated_amount']);
            }
        }
    }
}

