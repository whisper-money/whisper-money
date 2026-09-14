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

        // Split by the sign of each category's net, not by its type: a refund
        // booked to an expense category nets positive and crosses over to the
        // income side rather than dropping out of the chart.
        [$incomeCategories, $expenseCategories] = $this->sankeyColumns($user->id, $user->currency_code, $from, $to, $drillParentId);

        return $this->cashflowJson([
            'income_categories' => $incomeCategories->values(),
            'expense_categories' => $expenseCategories->values(),
            'total_income' => $incomeCategories->sum('amount'),
            'total_expense' => $expenseCategories->sum('amount'),
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

    /**
     * The sankey's two columns. Every cashflow category is netted once and then
     * lands on whichever side its net points at, so nothing is dropped for
     * ending up on the "wrong" side.
     *
     * @return array{0: Collection<int, array<string, mixed>>, 1: Collection<int, array<string, mixed>>}
     */
    private function sankeyColumns(string $userId, string $userCurrency, Carbon $from, Carbon $to, ?string $drillParentId): array
    {
        $transactions = $this->transactionsForPeriod($userId, $userCurrency, $from, $to);

        $netted = $this->netAmountsByCategory(
            $transactions,
            $userCurrency,
            fn (Transaction $transaction): bool => $this->cashflowSideOf($transaction->category) !== null,
        );

        // Signed amounts all the way through the roll-up, so a parent nets its
        // children against each other before it picks a side.
        $rolledUp = collect($this->tree->rollUp($netted->values()->all(), $userId, $drillParentId));

        return [
            $this->sankeyColumn($rolledUp, CategoryType::Income, $transactions, $userCurrency, $drillParentId),
            $this->sankeyColumn($rolledUp, CategoryType::Expense, $transactions, $userCurrency, $drillParentId),
        ];
    }

    /**
     * One sankey column: the rolled-up rows whose net points at $side, as
     * positive flows. A sankey cannot draw a negative flow, so a category that
     * crossed over says so in its label instead.
     *
     * @param  Collection<int, array<string, mixed>>  $rolledUp
     * @param  Collection<int, Transaction>  $transactions
     * @return Collection<int, array<string, mixed>>
     */
    private function sankeyColumn(Collection $rolledUp, CategoryType $side, Collection $transactions, string $userCurrency, ?string $drillParentId): Collection
    {
        $rows = $rolledUp
            ->filter(fn (array $row): bool => $this->amountMatchesSide($row['amount'], $side))
            ->map(fn (array $row): array => [
                ...$row,
                'amount' => abs($row['amount']),
                'category' => $this->labelCrossover($row['category'], $side),
            ])
            ->values()
            ->all();

        return collect($this->appendUncategorized($rows, $transactions, $userCurrency, $side, $drillParentId));
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

                    // Signed, so a refund lowers the month's expense bar instead
                    // of being dropped from it.
                    $amount = $this->orientToSide($this->sumConvertedAmounts($categoryTransactions, $userCurrency), $type);

                    if ($type === CategoryType::Income) {
                        $income += $amount;
                    } else {
                        $expense += $amount;
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
            fn (Transaction $transaction): bool => $transaction->categoryType() === $type,
        );

        // The list keeps every category on its own side and shows the signed
        // net, so a refund reads as a negative row instead of vanishing.
        $rolledUp = array_map(
            fn (array $item): array => [...$item, 'amount' => $this->orientToSide($item['amount'], $type)],
            $this->tree->rollUp($categorized->values()->all(), $userId, $drillParentId),
        );

        return collect($this->appendUncategorized($rolledUp, $transactions, $userCurrency, $type, $drillParentId));
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
     * keeping the sign of the net: a category is worth what it nets to, and it
     * is the caller that decides what a net on the "wrong" side means.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @param  callable(Transaction): bool  $belongsToSide
     * @return Collection<string, array{category_id: string, category: Category, amount: int}>
     */
    private function netAmountsByCategory(Collection $transactions, string $userCurrency, callable $belongsToSide): Collection
    {
        return $transactions
            ->filter($belongsToSide)
            ->groupBy('category_id')
            ->map(fn (Collection $categoryTransactions): array => [
                'category_id' => $categoryTransactions->first()->category_id,
                'category' => $categoryTransactions->first()->category,
                'amount' => $this->sumConvertedAmounts($categoryTransactions, $userCurrency),
            ]);
    }

    /**
     * The cashflow side a category belongs to before anything is netted.
     * Savings and investments are money leaving the cashflow, so they sit on
     * the expense side; a transfer follows its configured direction. Anything
     * else — a hidden transfer, no category at all — is on neither side.
     */
    private function cashflowSideOf(?Category $category): ?CategoryType
    {
        return match ($category?->type) {
            CategoryType::Income => CategoryType::Income,
            CategoryType::Expense, CategoryType::Savings, CategoryType::Investment => CategoryType::Expense,
            CategoryType::Transfer => match ($category->cashflow_direction) {
                CategoryCashflowDirection::Inflow => CategoryType::Income,
                CategoryCashflowDirection::Outflow => CategoryType::Expense,
                default => null,
            },
            default => null,
        };
    }

    /**
     * Renames a category that ended up on the opposite side of the one it
     * belongs to, so the sankey can state the crossover in words instead of
     * drawing a flow backwards.
     */
    private function labelCrossover(Category $category, CategoryType $side): Category
    {
        $naturalSide = $this->cashflowSideOf($category);

        if ($naturalSide === null || $naturalSide === $side) {
            return $category;
        }

        return (clone $category)->forceFill([
            'name' => $side === CategoryType::Income
                ? __(':name (refund)', ['name' => $category->name])
                : __(':name (reversal)', ['name' => $category->name]),
        ]);
    }

    /**
     * Appends the transactions with no category as a single synthetic row. Only
     * at the top level: a drilled-down parent has no uncategorized children.
     *
     * @param  array<int, array{category_id: ?string, category: Category|null, amount: int, has_children: bool, is_direct: bool}>  $rolledUp
     * @param  Collection<int, Transaction>  $transactions
     * @return array<int, array{category_id: ?string, category: Category|null, amount: int, has_children: bool, is_direct: bool}>
     */
    private function appendUncategorized(array $rolledUp, Collection $transactions, string $userCurrency, CategoryType $type, ?string $drillParentId): array
    {
        $uncategorized = $transactions
            ->filter(fn (Transaction $transaction): bool => $transaction->category_id === null
                && $this->amountMatchesSide($transaction->amount, $type))
            ->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $userCurrency));

        if ($drillParentId === null && $uncategorized != 0) {
            $rolledUp[] = [
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
            ];
        }

        return $rolledUp;
    }

    /**
     * A signed net as the given side reads it: positive means the money moved
     * the way that side expects (earned on the income side, spent on the
     * expense side), negative means it came back.
     */
    private function orientToSide(int $amount, CategoryType $type): int
    {
        return $type === CategoryType::Income ? $amount : -$amount;
    }

    private function amountMatchesSide(int $amount, CategoryType $type): bool
    {
        return $this->orientToSide($amount, $type) > 0;
    }
}
