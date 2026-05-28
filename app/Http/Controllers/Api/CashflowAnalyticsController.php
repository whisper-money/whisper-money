<?php

namespace App\Http\Controllers\Api;

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\ExchangeRateService;
use App\Services\PeriodComparator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CashflowAnalyticsController extends Controller
{
    public function __construct(private ExchangeRateService $exchangeRateService) {}

    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $period = PeriodComparator::fromRequest($validated);
        $previousPeriod = $period->previous();
        $user = $request->user();

        return $this->cashflowJson(
            $this->calculateCashflowSummaries($user->id, $user->currency_code, $period, $previousPeriod)
        );
    }

    public function sankey(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $from = Carbon::parse($validated['from']);
        $to = Carbon::parse($validated['to']);
        $user = $request->user();

        // Split by sign, not by category type: a single category can appear on
        // both sides when it has both incoming and outgoing transactions.
        $incomeCategories = $this->getSankeyBreakdown($user->id, $user->currency_code, $from, $to, '>');
        $expenseCategories = $this->getSankeyBreakdown($user->id, $user->currency_code, $from, $to, '<');

        $totalIncome = $incomeCategories->sum('amount');
        $totalExpense = $expenseCategories->sum('amount');

        return $this->cashflowJson([
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
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $user = $request->user();

        if (isset($validated['from'], $validated['to'])) {
            $start = Carbon::parse($validated['from'])->startOfMonth();
            $end = Carbon::parse($validated['to'])->endOfMonth();
        } else {
            $months = $validated['months'] ?? 12;
            $end = isset($validated['to'])
                ? Carbon::parse($validated['to'])->endOfMonth()
                : Carbon::now()->endOfMonth();
            $start = $end->copy()->subMonthsNoOverflow($months - 1)->startOfMonth();
        }

        $monthlyTotals = $this->getMonthlyTrendTotals($user->id, $user->currency_code, $start, $end);

        $data = [];
        $current = $start->copy();

        while ($current->lte($end)) {
            $monthKey = $current->format('Y-m');
            $totals = $monthlyTotals->get($monthKey);
            $income = (int) ($totals['income'] ?? 0);
            $expense = (int) ($totals['expense'] ?? 0);

            $data[] = [
                'month' => $monthKey,
                'income' => $income,
                'expense' => $expense,
                'net' => $income - $expense,
            ];

            $current->addMonth();
        }

        return $this->cashflowJson([
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
        $user = $request->user();

        $categoryType = $validated['type'] === 'income' ? CategoryType::Income : CategoryType::Expense;

        $current = $this->getCategoryBreakdown($user->id, $user->currency_code, $period->from, $period->to, $categoryType);
        $previous = $this->getCategoryBreakdown($user->id, $user->currency_code, $previousPeriod->from, $previousPeriod->to, $categoryType);

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

        return $this->cashflowJson([
            'data' => $currentWithPercentage,
            'total' => $currentTotal,
            'previous_total' => $previousTotal,
        ]);
    }

    private function cashflowJson(array $data): JsonResponse
    {
        return response()
            ->json($data)
            ->header('Cache-Control', 'no-store, private');
    }

    private function calculateCashflowSummaries(string $userId, string $userCurrency, PeriodComparator $period, PeriodComparator $previousPeriod): array
    {
        $transactions = Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$previousPeriod->from, $period->to])
            ->with(['account', 'category'])
            ->get();

        $this->preloadExchangeRates($transactions, $userCurrency);

        return [
            'current' => $this->cashflowSummaryFromTransactions(
                $this->transactionsForPeriod($transactions, $period->from, $period->to),
                $userCurrency,
            ),
            'previous' => $this->cashflowSummaryFromTransactions(
                $this->transactionsForPeriod($transactions, $previousPeriod->from, $previousPeriod->to),
                $userCurrency,
            ),
        ];
    }

    private function cashflowSummaryFromTransactions(Collection $transactions, string $userCurrency): array
    {
        $income = max(0, $this->sumTransactions($transactions, $userCurrency, CategoryType::Income));
        $expense = max(0, -$this->sumTransactions($transactions, $userCurrency, CategoryType::Expense));
        $savings = $this->sumOutflowTransactions($transactions, $userCurrency, CategoryType::Savings);
        $investments = $this->sumOutflowTransactions($transactions, $userCurrency, CategoryType::Investment);

        $net = $income - $expense;
        $savingsRate = $income > 0 ? round((($income - $expense) / $income) * 100, 1) : 0;

        return [
            'income' => $income,
            'expense' => $expense,
            'net' => $net,
            'savings_rate' => $savingsRate,
            'savings' => $savings,
            'investments' => $investments,
        ];
    }

    private function sumTransactions(Collection $transactions, string $userCurrency, CategoryType $type): int
    {
        return $transactions
            ->filter(function (Transaction $transaction) use ($type): bool {
                if ($this->categoryType($transaction) === $type) {
                    return true;
                }

                return $transaction->category_id === null
                    && $this->matchesSign($transaction->amount, $type === CategoryType::Income ? '>' : '<');
            })
            ->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $userCurrency));
    }

    private function sumOutflowTransactions(Collection $transactions, string $userCurrency, CategoryType $type): int
    {
        return abs($transactions
            ->filter(fn (Transaction $transaction): bool => $this->categoryType($transaction) === $type
                && $transaction->amount < 0)
            ->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $userCurrency)));
    }

    private function getSankeyBreakdown(string $userId, string $userCurrency, Carbon $from, Carbon $to, string $operator): Collection
    {
        $isIncome = $operator === '>';
        $type = $isIncome ? CategoryType::Income : CategoryType::Expense;
        $transactions = Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->with(['account', 'category'])
            ->get();

        $this->preloadExchangeRates($transactions, $userCurrency);

        $regularCategories = $transactions
            ->filter(function (Transaction $transaction) use ($type): bool {
                $categoryType = $this->categoryType($transaction);

                return $transaction->category_id !== null
                    && ($categoryType === $type
                        || ($type === CategoryType::Expense
                            && in_array($categoryType, [CategoryType::Savings, CategoryType::Investment], true)));
            })
            ->groupBy('category_id')
            ->map(function (Collection $transactions) use ($userCurrency): array {
                $totalAmount = $transactions->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $userCurrency));

                return [
                    'category_id' => $transactions->first()->category_id,
                    'category' => $transactions->first()->category,
                    'amount' => abs($totalAmount),
                    'total_amount' => $totalAmount,
                ];
            })
            ->filter(fn (array $item): bool => $this->categoryNetAmountMatchesSide($item['total_amount'], $type))
            ->map(fn (array $item): array => [
                'category_id' => $item['category_id'],
                'category' => $item['category'],
                'amount' => $item['amount'],
            ]);

        $transferCategories = $transactions
            ->filter(function (Transaction $transaction) use ($isIncome): bool {
                return $transaction->category_id !== null
                    && $this->categoryType($transaction) === CategoryType::Transfer
                    && $this->categoryCashflowDirection($transaction) === ($isIncome
                        ? CategoryCashflowDirection::Inflow
                        : CategoryCashflowDirection::Outflow);
            })
            ->groupBy('category_id')
            ->map(function (Collection $transactions) use ($userCurrency): array {
                $totalAmount = $transactions->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $userCurrency));

                return [
                    'category_id' => $transactions->first()->category_id,
                    'category' => $transactions->first()->category,
                    'amount' => abs($totalAmount),
                    'total_amount' => $totalAmount,
                ];
            })
            ->filter(fn (array $item): bool => $isIncome ? $item['total_amount'] > 0 : $item['total_amount'] < 0)
            ->map(fn (array $item): array => [
                'category_id' => $item['category_id'],
                'category' => $item['category'],
                'amount' => $item['amount'],
            ]);

        $categorized = $regularCategories->concat($transferCategories)->values();

        $uncategorized = $transactions
            ->filter(function (Transaction $transaction) use ($operator): bool {
                return $transaction->category_id === null
                    && $this->matchesSign($transaction->amount, $operator);
            })
            ->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $userCurrency));

        if ($uncategorized != 0) {
            $categorized->push([
                'category_id' => null,
                'category' => (new Category)->forceFill([
                    'id' => null,
                    'name' => $isIncome ? __('Unknown Income') : __('Unknown Expense'),
                    'type' => $isIncome ? CategoryType::Income : CategoryType::Expense,
                    'color' => 'gray',
                    'icon' => 'HelpCircle',
                ]),
                'amount' => abs($uncategorized),
            ]);
        }

        return $categorized;
    }

    private function getMonthlyTrendTotals(string $userId, string $userCurrency, Carbon $from, Carbon $to): Collection
    {
        $transactions = Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->with(['account', 'category'])
            ->get();

        $this->preloadExchangeRates($transactions, $userCurrency);

        return $transactions
            ->groupBy(fn (Transaction $transaction): string => $transaction->transaction_date->format('Y-m'))
            ->map(function (Collection $transactions) use ($userCurrency): array {
                $income = 0;
                $expense = 0;

                $categorized = $transactions
                    ->filter(fn (Transaction $transaction): bool => $transaction->category_id !== null)
                    ->groupBy('category_id');

                foreach ($categorized as $categoryTransactions) {
                    $firstTransaction = $categoryTransactions->first();
                    $type = $this->categoryType($firstTransaction);

                    if (! in_array($type, [CategoryType::Income, CategoryType::Expense], true)) {
                        continue;
                    }

                    $amount = $categoryTransactions->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $userCurrency));

                    if ($this->categoryNetAmountMatchesSide($amount, $type)) {
                        if ($type === CategoryType::Income) {
                            $income += $amount;
                        } else {
                            $expense += abs($amount);
                        }
                    }
                }

                foreach ($transactions->whereNull('category_id') as $transaction) {
                    $amount = $this->convertTransactionAmount($transaction, $userCurrency);

                    if ($transaction->amount > 0) {
                        $income += $amount;
                    }

                    if ($transaction->amount < 0) {
                        $expense += abs($amount);
                    }
                }

                return [
                    'income' => $income,
                    'expense' => $expense,
                ];
            });
    }

    private function getCategoryBreakdown(string $userId, string $userCurrency, Carbon $from, Carbon $to, CategoryType $type): Collection
    {
        $transactions = Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->with(['account', 'category'])
            ->get();

        $this->preloadExchangeRates($transactions, $userCurrency);

        $categorized = $transactions
            ->filter(fn (Transaction $transaction): bool => $this->categoryType($transaction) === $type)
            ->groupBy('category_id')
            ->map(function (Collection $transactions) use ($userCurrency): array {
                $totalAmount = $transactions->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $userCurrency));

                return [
                    'category_id' => $transactions->first()->category_id,
                    'category' => $transactions->first()->category,
                    'amount' => abs($totalAmount),
                    'total_amount' => $totalAmount,
                ];
            })
            ->filter(fn (array $item): bool => $this->categoryNetAmountMatchesSide($item['total_amount'], $type))
            ->map(fn (array $item): array => [
                'category_id' => $item['category_id'],
                'category' => $item['category'],
                'amount' => $item['amount'],
            ]);

        $uncategorized = $transactions
            ->filter(function (Transaction $transaction) use ($type): bool {
                return $transaction->category_id === null
                    && $this->matchesSign($transaction->amount, $type === CategoryType::Income ? '>' : '<');
            })
            ->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $userCurrency));

        // Add uncategorized as a special category if there are any
        if ($uncategorized != 0) {
            $categorized->push([
                'category_id' => null,
                'category' => (new Category)->forceFill([
                    'id' => null,
                    'name' => $type === CategoryType::Income ? __('Unknown Income') : __('Unknown Expense'),
                    'type' => $type,
                    'color' => 'gray',
                    'icon' => 'HelpCircle',
                ]),
                'amount' => abs($uncategorized),
            ]);
        }

        return $categorized;
    }

    private function transactionsForPeriod(Collection $transactions, Carbon $from, Carbon $to): Collection
    {
        return $transactions->filter(function (Transaction $transaction) use ($from, $to): bool {
            return $transaction->transaction_date->betweenIncluded($from, $to);
        });
    }

    private function convertTransactionAmount(Transaction $transaction, string $userCurrency): int
    {
        return $this->exchangeRateService->convert(
            $transaction->currency_code ?: $transaction->account?->currency_code ?: $userCurrency,
            $userCurrency,
            $transaction->amount,
            $transaction->transaction_date->toDateString(),
        );
    }

    private function preloadExchangeRates(Collection $transactions, string $userCurrency): void
    {
        $dates = $transactions
            ->filter(fn (Transaction $transaction): bool => strcasecmp($transaction->currency_code ?: $transaction->account?->currency_code ?: $userCurrency, $userCurrency) !== 0)
            ->map(fn (Transaction $transaction): string => $transaction->transaction_date->toDateString())
            ->unique()
            ->values();

        if ($dates->isEmpty()) {
            return;
        }

        $this->exchangeRateService->preloadRates($userCurrency, $dates);
    }

    private function categoryType(Transaction $transaction): ?CategoryType
    {
        $type = $transaction->category?->getAttribute('type');

        if ($type instanceof CategoryType) {
            return $type;
        }

        return is_string($type) ? CategoryType::tryFrom($type) : null;
    }

    private function categoryCashflowDirection(Transaction $transaction): ?CategoryCashflowDirection
    {
        $direction = $transaction->category?->getAttribute('cashflow_direction');

        if ($direction instanceof CategoryCashflowDirection) {
            return $direction;
        }

        return is_string($direction) ? CategoryCashflowDirection::tryFrom($direction) : null;
    }

    private function matchesSign(int $amount, string $operator): bool
    {
        return $operator === '>' ? $amount > 0 : $amount < 0;
    }

    private function categoryNetAmountMatchesSide(int $amount, CategoryType $type): bool
    {
        return $type === CategoryType::Income ? $amount > 0 : $amount < 0;
    }
}
