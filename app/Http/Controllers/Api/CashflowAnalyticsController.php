<?php

namespace App\Http\Controllers\Api;

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Http\Controllers\Api\Concerns\ConvertsTransactionCurrency;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\CashflowSummaryService;
use App\Services\CategoryTree;
use App\Services\ExchangeRateService;
use App\Services\PeriodComparator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class CashflowAnalyticsController extends Controller
{
    use ConvertsTransactionCurrency;

    private const MAX_TREND_MONTHS = 24;

    public function __construct(
        private ExchangeRateService $exchangeRateService,
        private CategoryTree $tree,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $period = PeriodComparator::fromRequest($validated);
        $previousPeriod = $period->previous();
        $user = $request->user();
        $space = $user->activeSpace();

        return $this->cashflowJson(
            $this->calculateCashflowSummaries($space->id, $user->currency_code, $period, $previousPeriod)
        );
    }

    public function sankey(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
            'parent' => 'nullable|uuid',
        ]);

        $from = Carbon::parse($validated['from']);
        $to = Carbon::parse($validated['to']);
        $user = $request->user();
        $space = $user->activeSpace();
        $drillParentId = $validated['parent'] ?? null;

        // Split by sign, not by category type: a single category can appear on
        // both sides when it has both incoming and outgoing transactions.
        $incomeCategories = $this->getSankeyBreakdown($space->id, $user->currency_code, $from, $to, '>', $drillParentId);
        $expenseCategories = $this->getSankeyBreakdown($space->id, $user->currency_code, $from, $to, '<', $drillParentId);

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
            'months' => 'nullable|integer|min:1|max:'.self::MAX_TREND_MONTHS,
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $user = $request->user();
        $space = $user->activeSpace();

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

        // Bound the window to the most recent MAX_TREND_MONTHS months so an
        // unbounded from/to range cannot make the month loop below iterate
        // indefinitely and exhaust the request timeout.
        $earliestStart = $end->copy()->subMonthsNoOverflow(self::MAX_TREND_MONTHS - 1)->startOfMonth();

        if ($start->lt($earliestStart)) {
            $start = $earliestStart;
        }

        $monthlyTotals = $this->getMonthlyTrendTotals($space->id, $user->currency_code, $start, $end);

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
            'parent' => 'nullable|uuid',
        ]);

        $period = PeriodComparator::fromRequest($validated);
        $previousPeriod = $period->previous();
        $user = $request->user();
        $space = $user->activeSpace();
        $drillParentId = $validated['parent'] ?? null;

        $categoryType = $validated['type'] === 'income' ? CategoryType::Income : CategoryType::Expense;

        $current = $this->getCategoryBreakdown($space->id, $user->currency_code, $period->from, $period->to, $categoryType, $drillParentId);
        $previous = $this->getCategoryBreakdown($space->id, $user->currency_code, $previousPeriod->from, $previousPeriod->to, $categoryType, $drillParentId);

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
                'has_children' => $item['has_children'] ?? false,
                'is_direct' => $item['is_direct'] ?? false,
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

    private function calculateCashflowSummaries(string $spaceId, string $userCurrency, PeriodComparator $period, PeriodComparator $previousPeriod): array
    {
        $transactions = Transaction::query()
            ->where('transactions.space_id', $spaceId)
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

        return [
            ...CashflowSummaryService::summarize($income, $expense),
            'savings' => $savings,
            'investments' => $investments,
        ];
    }

    private function sumTransactions(Collection $transactions, string $userCurrency, CategoryType $type): int
    {
        $onSide = match ($type) {
            CategoryType::Income => fn (Transaction $transaction): bool => $transaction->isIncomeSide(),
            CategoryType::Expense => fn (Transaction $transaction): bool => $transaction->isExpenseSide(),
            default => throw new InvalidArgumentException("sumTransactions only supports Income and Expense, got {$type->value}."),
        };

        return $transactions
            ->filter($onSide)
            ->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $userCurrency));
    }

    private function sumOutflowTransactions(Collection $transactions, string $userCurrency, CategoryType $type): int
    {
        return abs($transactions
            ->filter(fn (Transaction $transaction): bool => $transaction->categoryType() === $type
                && $transaction->amount < 0)
            ->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $userCurrency)));
    }

    private function getSankeyBreakdown(string $spaceId, string $userCurrency, Carbon $from, Carbon $to, string $operator, ?string $drillParentId = null): Collection
    {
        $isIncome = $operator === '>';
        $type = $isIncome ? CategoryType::Income : CategoryType::Expense;
        $transactions = Transaction::query()
            ->where('transactions.space_id', $spaceId)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->with(['account', 'category'])
            ->get();

        $this->preloadExchangeRates($transactions, $userCurrency);

        $regularCategories = $transactions
            ->filter(function (Transaction $transaction) use ($type): bool {
                $categoryType = $transaction->categoryType();

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
                    && $transaction->categoryType() === CategoryType::Transfer
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

        $categorized = collect($this->tree->rollUp(
            $regularCategories->concat($transferCategories)->values()->all(),
            $spaceId,
            $drillParentId,
        ));

        $uncategorized = $transactions
            ->filter(function (Transaction $transaction) use ($operator): bool {
                return $transaction->category_id === null
                    && $this->matchesSign($transaction->amount, $operator);
            })
            ->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $userCurrency));

        if ($drillParentId === null && $uncategorized != 0) {
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
                'has_children' => false,
                'is_direct' => false,
            ]);
        }

        return $categorized;
    }

    private function getMonthlyTrendTotals(string $spaceId, string $userCurrency, Carbon $from, Carbon $to): Collection
    {
        $transactions = Transaction::query()
            ->where('transactions.space_id', $spaceId)
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
                    $type = $firstTransaction->categoryType();

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

    private function getCategoryBreakdown(string $spaceId, string $userCurrency, Carbon $from, Carbon $to, CategoryType $type, ?string $drillParentId = null): Collection
    {
        $transactions = Transaction::query()
            ->where('transactions.space_id', $spaceId)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->with(['account', 'category'])
            ->get();

        $this->preloadExchangeRates($transactions, $userCurrency);

        $categorized = $transactions
            ->filter(fn (Transaction $transaction): bool => $transaction->categoryType() === $type)
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

        $categorized = collect($this->tree->rollUp($categorized->values()->all(), $spaceId, $drillParentId));

        $uncategorized = $transactions
            ->filter(function (Transaction $transaction) use ($type): bool {
                return $transaction->category_id === null
                    && $this->matchesSign($transaction->amount, $type === CategoryType::Income ? '>' : '<');
            })
            ->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $userCurrency));

        // Add uncategorized as a special category if there are any
        if ($drillParentId === null && $uncategorized != 0) {
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
                'has_children' => false,
                'is_direct' => false,
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
