<?php

namespace App\Http\Controllers\Api;

use App\Enums\CategoryType;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\PeriodComparator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashflowAnalyticsController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $period = PeriodComparator::fromRequest($validated);
        $previousPeriod = $period->previous();

        $current = $this->calculateCashflowSummary($request->user()->id, $period->from, $period->to);
        $previous = $this->calculateCashflowSummary($request->user()->id, $previousPeriod->from, $previousPeriod->to);

        return response()->json([
            'current' => $current,
            'previous' => $previous,
        ]);
    }

    public function sankey(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $from = Carbon::parse($validated['from']);
        $to = Carbon::parse($validated['to']);
        $userId = $request->user()->id;

        $incomeCategories = $this->getCategoryBreakdown($userId, $from, $to, CategoryType::Income);
        $expenseCategories = $this->getCategoryBreakdown($userId, $from, $to, CategoryType::Expense);

        $totalIncome = $incomeCategories->sum('amount');
        $totalExpense = $expenseCategories->sum('amount');

        return response()->json([
            'income_categories' => $incomeCategories->values(),
            'expense_categories' => $expenseCategories->values(),
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
        ]);
    }

    public function trend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'months' => 'nullable|integer|min:1|max:24',
        ]);

        $months = $validated['months'] ?? 12;
        $userId = $request->user()->id;

        $end = Carbon::now()->endOfMonth();
        $start = Carbon::now()->subMonths($months - 1)->startOfMonth();

        $data = [];
        $current = $start->copy();

        while ($current->lte($end)) {
            $monthStart = $current->copy()->startOfMonth();
            $monthEnd = $current->copy()->endOfMonth();

            $income = $this->getTransactionSum($userId, $monthStart, $monthEnd, CategoryType::Income);
            $expense = $this->getTransactionSum($userId, $monthStart, $monthEnd, CategoryType::Expense);

            $data[] = [
                'month' => $current->format('Y-m'),
                'income' => $income,
                'expense' => abs($expense),
                'net' => $income + $expense, // expense is negative, so this gives net
            ];

            $current->addMonth();
        }

        return response()->json([
            'data' => $data,
        ]);
    }

    public function breakdown(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
            'type' => 'required|in:income,expense',
        ]);

        $period = PeriodComparator::fromRequest($validated);
        $previousPeriod = $period->previous();
        $userId = $request->user()->id;

        $categoryType = $validated['type'] === 'income' ? CategoryType::Income : CategoryType::Expense;

        $current = $this->getCategoryBreakdown($userId, $period->from, $period->to, $categoryType);
        $previous = $this->getCategoryBreakdown($userId, $previousPeriod->from, $previousPeriod->to, $categoryType);

        $currentTotal = $current->sum('amount');
        $previousTotal = $previous->sum('amount');

        // Add percentage and previous amount to current
        $currentWithPercentage = $current->map(function ($item) use ($currentTotal, $previous) {
            $previousAmount = $previous->firstWhere('category_id', $item['category_id'])['amount'] ?? 0;

            return [
                'category' => $item['category'],
                'category_id' => $item['category_id'],
                'amount' => $item['amount'],
                'percentage' => $currentTotal > 0 ? round(($item['amount'] / $currentTotal) * 100, 1) : 0,
                'previous_amount' => $previousAmount,
            ];
        })->sortByDesc('amount')->values();

        return response()->json([
            'data' => $currentWithPercentage,
            'total' => $currentTotal,
            'previous_total' => $previousTotal,
        ]);
    }

    private function calculateCashflowSummary(string $userId, Carbon $from, Carbon $to): array
    {
        $income = $this->getTransactionSum($userId, $from, $to, CategoryType::Income);
        $expense = abs($this->getTransactionSum($userId, $from, $to, CategoryType::Expense));

        $net = $income - $expense;
        $savingsRate = $income > 0 ? round((($income - $expense) / $income) * 100, 1) : 0;

        return [
            'income' => $income,
            'expense' => $expense,
            'net' => $net,
            'savings_rate' => $savingsRate,
        ];
    }

    private function getTransactionSum(string $userId, Carbon $from, Carbon $to, CategoryType $type): int
    {
        return Transaction::query()
            ->where('user_id', $userId)
            ->whereBetween('transaction_date', [$from, $to])
            ->where(function ($q) use ($type) {
                $q->whereHas('category', function ($q) use ($type) {
                    $q->where('type', $type);
                })
                    ->orWhere(function ($q) use ($type) {
                        $q->whereNull('category_id')
                            ->where('amount', $type === CategoryType::Income ? '>' : '<', 0);
                    });
            })
            ->sum('amount');
    }

    private function getCategoryBreakdown(string $userId, Carbon $from, Carbon $to, CategoryType $type)
    {
        // Get categorized transactions
        $categorized = Transaction::query()
            ->where('user_id', $userId)
            ->whereBetween('transaction_date', [$from, $to])
            ->whereHas('category', function ($q) use ($type) {
                $q->where('type', $type);
            })
            ->select('category_id', DB::raw('sum(amount) as total_amount'))
            ->groupBy('category_id')
            ->with('category')
            ->get()
            ->map(function ($item) {
                return [
                    'category_id' => $item->category_id,
                    'category' => $item->category,
                    'amount' => abs($item->total_amount),
                ];
            });

        // Get uncategorized transactions
        $uncategorized = Transaction::query()
            ->where('user_id', $userId)
            ->whereBetween('transaction_date', [$from, $to])
            ->whereNull('category_id')
            ->where('amount', $type === CategoryType::Income ? '>' : '<', 0)
            ->sum('amount');

        // Add uncategorized as a special category if there are any
        if ($uncategorized != 0) {
            $categorized->push([
                'category_id' => null,
                'category' => (object) [
                    'id' => null,
                    'name' => $type === CategoryType::Income ? 'Unknown Income' : 'Unknown Expense',
                    'type' => $type,
                    'color' => 'gray',
                    'icon' => 'HelpCircle',
                ],
                'amount' => abs($uncategorized),
            ]);
        }

        return $categorized;
    }
}
