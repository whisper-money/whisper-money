<?php

namespace App\Http\Controllers\Api;

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\CashflowSummaryService;
use App\Services\CategoryTree;
use App\Services\Concerns\ConvertsTransactionCurrency;
use App\Services\ExchangeRateService;
use App\Services\PeriodComparator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CashflowAnalyticsController extends Controller
{
    use ConvertsTransactionCurrency;

    private const MAX_TREND_MONTHS = 24;

    public function __construct(
        private ExchangeRateService $exchangeRateService,
        private CategoryTree $tree,
        private CashflowSummaryService $summaries,
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

        return $this->cashflowJson(
            $this->summaries->forComparedPeriods($user->id, $user->currency_code, $period, $previousPeriod)
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
        $drillParentId = $validated['parent'] ?? null;

        // Split by sign, not by category type: a single category can appear on
        // both sides when it has both incoming and outgoing transactions.
        $incomeCategories = $this->getSankeyBreakdown($user->id, $user->currency_code, $from, $to, '>', $drillParentId);
        $expenseCategories = $this->getSankeyBreakdown($user->id, $user->currency_code, $from, $to, '<', $drillParentId);

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
            'parent' => 'nullable|uuid',
        ]);

        $period = PeriodComparator::fromRequest($validated);
        $previousPeriod = $period->previous();
        $user = $request->user();
        $drillParentId = $validated['parent'] ?? null;

        $categoryType = $validated['type'] === 'income' ? CategoryType::Income : CategoryType::Expense;

        $current = $this->getCategoryBreakdown($user->id, $user->currency_code, $period->from, $period->to, $categoryType, $drillParentId);
        $previous = $this->getCategoryBreakdown($user->id, $user->currency_code, $previousPeriod->from, $previousPeriod->to, $categoryType, $drillParentId);

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

    private function getSankeyBreakdown(string $userId, string $userCurrency, Carbon $from, Carbon $to, string $operator, ?string $drillParentId = null): Collection
    {
        $type = $operator === '>' ? CategoryType::Income : CategoryType::Expense;
        $transactions = $this->transactionsForPeriod($userId, $userCurrency, $from, $to);

        $regularCategories = $this->netAmountsByCategory(
            $transactions,
            $userCurrency,
            $type,
            fn (Transaction $transaction): bool => $this->belongsToCashflowSide($transaction, $type),
        );

        $transferCategories = $this->netAmountsByCategory(
            $transactions,
            $userCurrency,
            $type,
            fn (Transaction $transaction): bool => $this->isTransferOnCashflowSide($transaction, $type),
        );

        $categorized = collect($this->tree->rollUp(
            $regularCategories->concat($transferCategories)->values()->all(),
            $userId,
            $drillParentId,
        ));

        return $this->appendUncategorized($categorized, $transactions, $userCurrency, $type, $drillParentId);
    }

    private function getMonthlyTrendTotals(string $userId, string $userCurrency, Carbon $from, Carbon $to): Collection
    {
        $transactions = $this->transactionsForPeriod($userId, $userCurrency, $from, $to);

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

                    if ($this->amountMatchesSide($amount, $type)) {
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

    private function getCategoryBreakdown(string $userId, string $userCurrency, Carbon $from, Carbon $to, CategoryType $type, ?string $drillParentId = null): Collection
    {
        $transactions = $this->transactionsForPeriod($userId, $userCurrency, $from, $to);

        $categorized = $this->netAmountsByCategory(
            $transactions,
            $userCurrency,
            $type,
            fn (Transaction $transaction): bool => $transaction->categoryType() === $type,
        );

        return $this->appendUncategorized(
            collect($this->tree->rollUp($categorized->values()->all(), $userId, $drillParentId)),
            $transactions,
            $userCurrency,
            $type,
            $drillParentId,
        );
    }

    /**
     * Every transaction in the window, with the exchange rates its conversion
     * needs already primed so the callers never hit the rate service per row.
     *
     * @return Collection<int, Transaction>
     */
    private function transactionsForPeriod(string $userId, string $userCurrency, Carbon $from, Carbon $to): Collection
    {
        $transactions = Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->countingTowardsTotals()
            ->with(['account', 'category'])
            ->get();

        $this->preloadExchangeRates($transactions, $userCurrency);

        return $transactions;
    }

    /**
     * Nets the transactions $belongsToSide selects into one row per category,
     * dropping the categories whose net lands on the opposite side of $type.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @param  callable(Transaction): bool  $belongsToSide
     * @return Collection<string, array{category_id: string, category: Category, amount: int}>
     */
    private function netAmountsByCategory(Collection $transactions, string $userCurrency, CategoryType $type, callable $belongsToSide): Collection
    {
        return $transactions
            ->filter($belongsToSide)
            ->groupBy('category_id')
            ->map(function (Collection $categoryTransactions) use ($userCurrency): array {
                $totalAmount = $categoryTransactions->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $userCurrency));

                return [
                    'category_id' => $categoryTransactions->first()->category_id,
                    'category' => $categoryTransactions->first()->category,
                    'amount' => abs($totalAmount),
                    'total_amount' => $totalAmount,
                ];
            })
            ->filter(fn (array $item): bool => $this->amountMatchesSide($item['total_amount'], $type))
            ->map(fn (array $item): array => [
                'category_id' => $item['category_id'],
                'category' => $item['category'],
                'amount' => $item['amount'],
            ]);
    }

    /**
     * Savings and investment categories are money leaving the cashflow, so they
     * sit on the expense side next to the plain expense categories.
     */
    private function belongsToCashflowSide(Transaction $transaction, CategoryType $type): bool
    {
        $categoryType = $transaction->categoryType();

        return $transaction->category_id !== null
            && ($categoryType === $type
                || ($type === CategoryType::Expense
                    && in_array($categoryType, [CategoryType::Savings, CategoryType::Investment], true)));
    }

    /**
     * A transfer category lands on whichever side its configured direction
     * points at, regardless of the sign of its individual transactions.
     */
    private function isTransferOnCashflowSide(Transaction $transaction, CategoryType $type): bool
    {
        return $transaction->category_id !== null
            && $transaction->categoryType() === CategoryType::Transfer
            && $this->categoryCashflowDirection($transaction) === ($type === CategoryType::Income
                ? CategoryCashflowDirection::Inflow
                : CategoryCashflowDirection::Outflow);
    }

    /**
     * Appends the transactions with no category as a single synthetic row. Only
     * at the top level: a drilled-down parent has no uncategorized children.
     *
     * @param  Collection<int, array<string, mixed>>  $categorized
     * @param  Collection<int, Transaction>  $transactions
     * @return Collection<int, array<string, mixed>>
     */
    private function appendUncategorized(Collection $categorized, Collection $transactions, string $userCurrency, CategoryType $type, ?string $drillParentId): Collection
    {
        $uncategorized = $transactions
            ->filter(fn (Transaction $transaction): bool => $transaction->category_id === null
                && $this->amountMatchesSide($transaction->amount, $type))
            ->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $userCurrency));

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

    private function categoryCashflowDirection(Transaction $transaction): ?CategoryCashflowDirection
    {
        $direction = $transaction->category?->getAttribute('cashflow_direction');

        if ($direction instanceof CategoryCashflowDirection) {
            return $direction;
        }

        return is_string($direction) ? CategoryCashflowDirection::tryFrom($direction) : null;
    }

    private function amountMatchesSide(int $amount, CategoryType $type): bool
    {
        return $type === CategoryType::Income ? $amount > 0 : $amount < 0;
    }
}
