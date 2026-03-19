<?php

namespace App\Http\Controllers\Api;

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Http\Controllers\Controller;
use App\Models\Category;
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

        // Split by sign, not by category type: a single category can appear on
        // both sides when it has both incoming and outgoing transactions.
        $incomeCategories = $this->getSankeyBreakdown($userId, $from, $to, '>');
        $expenseCategories = $this->getSankeyBreakdown($userId, $from, $to, '<');

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
            'to' => 'nullable|date',
        ]);

        $months = $validated['months'] ?? 12;
        $userId = $request->user()->id;

        $end = isset($validated['to'])
            ? Carbon::parse($validated['to'])->endOfMonth()
            : Carbon::now()->endOfMonth();
        $start = $end->copy()->subMonthsNoOverflow($months - 1)->startOfMonth();
        $monthlyTotals = $this->getMonthlyTrendTotals($userId, $start, $end);

        $data = [];
        $current = $start->copy();

        while ($current->lte($end)) {
            $monthKey = $current->format('Y-m');
            $totals = $monthlyTotals->get($monthKey);
            $income = (int) ($totals->income ?? 0);
            $expense = (int) ($totals->expense ?? 0);

            $data[] = [
                'month' => $monthKey,
                'income' => $income,
                'expense' => $expense,
                'net' => $income - $expense,
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
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->where(function ($q) use ($type) {
                $q->whereExists(function ($sub) use ($type) {
                    $sub->select(DB::raw(1))
                        ->from('categories')
                        ->whereColumn('categories.id', 'transactions.category_id')
                        ->where('categories.type', $type);
                })
                    ->orWhere(function ($q) use ($type) {
                        $q->whereNull('transactions.category_id')
                            ->where('transactions.amount', $type === CategoryType::Income ? '>' : '<', 0);
                    });
            })
            ->sum('transactions.amount');
    }

    private function getSankeyBreakdown(string $userId, Carbon $from, Carbon $to, string $operator)
    {
        $isIncome = $operator === '>';

        // Group all transactions by category using the amount sign, not the category
        // type. This allows one category to appear on both sides of the Sankey when
        // it contains transactions of both signs (e.g. an income category that also
        // holds property expense payments will show income on the left and the
        // outgoing payments on the right).
        $categorized = Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->where('transactions.amount', $operator, 0)
            ->whereNotNull('transactions.category_id')
            ->join('categories', function ($join) use ($isIncome) {
                $join->on('transactions.category_id', '=', 'categories.id')
                    ->where(function ($q) use ($isIncome) {
                        $q->where('categories.type', '!=', CategoryType::Transfer)
                            ->orWhere(function ($q) use ($isIncome) {
                                $q->where('categories.type', CategoryType::Transfer)
                                    ->where(
                                        'categories.cashflow_direction',
                                        $isIncome
                                            ? CategoryCashflowDirection::Inflow
                                            : CategoryCashflowDirection::Outflow,
                                    );
                            });
                    });
            })
            ->select('transactions.category_id', DB::raw('sum(transactions.amount) as total_amount'))
            ->groupBy('transactions.category_id')
            ->with('category')
            ->get()
            ->map(function ($item) {
                return [
                    'category_id' => $item->category_id,
                    'category' => $item->category,
                    'amount' => abs($item->total_amount),
                ];
            });

        $uncategorized = Transaction::query()
            ->where('user_id', $userId)
            ->whereBetween('transaction_date', [$from, $to])
            ->whereNull('category_id')
            ->where('amount', $operator, 0)
            ->sum('amount');

        if ($uncategorized != 0) {
            $categorized->push([
                'category_id' => null,
                'category' => (new Category)->forceFill([
                    'id' => null,
                    'name' => $isIncome ? 'Unknown Income' : 'Unknown Expense',
                    'type' => $isIncome ? CategoryType::Income : CategoryType::Expense,
                    'color' => 'gray',
                    'icon' => 'HelpCircle',
                ]),
                'amount' => abs($uncategorized),
            ]);
        }

        return $categorized;
    }

    private function getMonthlyTrendTotals(string $userId, Carbon $from, Carbon $to)
    {
        return Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->leftJoin('categories', 'transactions.category_id', '=', 'categories.id')
            ->selectRaw("DATE_FORMAT(transactions.transaction_date, '%Y-%m') as month")
            ->selectRaw(
                'SUM(CASE WHEN ((categories.type = ? AND transactions.amount > 0) OR (transactions.category_id IS NULL AND transactions.amount > 0)) THEN transactions.amount ELSE 0 END) as income',
                [CategoryType::Income->value]
            )
            ->selectRaw(
                'SUM(CASE WHEN ((categories.type = ? AND transactions.amount < 0) OR (transactions.category_id IS NULL AND transactions.amount < 0)) THEN -transactions.amount ELSE 0 END) as expense',
                [CategoryType::Expense->value]
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month');
    }

    private function getCategoryBreakdown(string $userId, Carbon $from, Carbon $to, CategoryType $type)
    {
        // Get categorized transactions — filter by sign so that outgoing payments
        // in an income category (or refunds in an expense category) are excluded.
        // This ensures the Sankey shows the actual gross flow for each side, not
        // the net which could be misleading when categories contain mixed-sign entries.
        $categorized = Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->where('transactions.amount', $type === CategoryType::Income ? '>' : '<', 0)
            ->join('categories', function ($join) use ($type) {
                $join->on('transactions.category_id', '=', 'categories.id')
                    ->where('categories.type', '=', $type);
            })
            ->select('transactions.category_id', DB::raw('sum(transactions.amount) as total_amount'))
            ->groupBy('transactions.category_id')
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
                'category' => (new Category)->forceFill([
                    'id' => null,
                    'name' => $type === CategoryType::Income ? 'Unknown Income' : 'Unknown Expense',
                    'type' => $type,
                    'color' => 'gray',
                    'icon' => 'HelpCircle',
                ]),
                'amount' => abs($uncategorized),
            ]);
        }

        return $categorized;
    }
}
