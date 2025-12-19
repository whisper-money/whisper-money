<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\BudgetCategory;
use App\Models\BudgetPeriodAllocation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BudgetAnalyticsController extends Controller
{
    public function history(Request $request, Budget $budget, BudgetCategory $budgetCategory): Response
    {
        $this->authorize('view', $budget);

        $periods = $budget->periods()
            ->orderBy('start_date', 'desc')
            ->take(6)
            ->with(['allocations' => function ($query) use ($budgetCategory) {
                $query->where('budget_category_id', $budgetCategory->id)
                    ->with('budgetTransactions');
            }])
            ->get();

        $historyData = $periods->map(function ($period) {
            $allocation = $period->allocations->first();

            return [
                'period_start' => $period->start_date,
                'period_end' => $period->end_date,
                'budgeted' => $allocation?->allocated_amount ?? 0,
                'spent' => $allocation?->calculateSpent() ?? 0,
            ];
        })->reverse()->values();

        return Inertia::render('budgets/analytics', [
            'budget' => $budget,
            'budgetCategory' => $budgetCategory->load('category'),
            'historyData' => $historyData,
        ]);
    }

    public function transactions(Request $request, BudgetPeriodAllocation $allocation)
    {
        $this->authorize('view', $allocation->budgetPeriod->budget);

        $transactions = $allocation->budgetTransactions()
            ->with(['transaction.category', 'transaction.account', 'transaction.labels'])
            ->get()
            ->pluck('transaction');

        return response()->json([
            'transactions' => $transactions,
        ]);
    }
}

