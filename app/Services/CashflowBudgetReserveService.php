<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Transaction;
use App\Services\Concerns\ConvertsTransactionCurrency;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the user's budgets still hold back from a cashflow period, and how much
 * of its spending they cover.
 *
 * @phpstan-type BudgetMarginRow array{budget_id: string, budget_period_id: string, name: string, start_date: string, end_date: string, allocated: int, spent: int, margin: int}
 */
class CashflowBudgetReserveService
{
    use ConvertsTransactionCurrency;

    public function __construct(
        private ExchangeRateService $exchangeRateService,
        private CategoryTree $tree,
    ) {}

    /**
     * `reserved` adds up the positive margins of the budget periods that
     * overlap the range and have not ended yet: money still earmarked for
     * spending. An over-budget period adds nothing rather than eating into the
     * rest, and a closed one adds nothing because whatever it left over is
     * already back in the net. `budgets` lists the same periods, with margins
     * that may be negative.
     *
     * `variable_expense` is the part of `$expense` booked to categories (or
     * their children) and labels tracked by a budget during the range;
     * `fixed_expense` is the rest, so the two always add up to `$expense`.
     *
     * Budget amounts are read as stored, in the user's currency, the same way
     * the budget screens read them.
     *
     * @return array{reserved: int, budgets: list<BudgetMarginRow>, variable_expense: int, fixed_expense: int}
     */
    public function forPeriod(string $userId, string $userCurrency, PeriodComparator $period, int $expense): array
    {
        $budgets = $this->openBudgetMargins($userId, $period);
        $variableExpense = $this->variableExpense($userId, $userCurrency, $period);

        return [
            'reserved' => array_sum(array_map(fn (array $row): int => max(0, $row['margin']), $budgets)),
            'budgets' => $budgets,
            'variable_expense' => $variableExpense,
            'fixed_expense' => $expense - $variableExpense,
        ];
    }

    /**
     * @return list<BudgetMarginRow>
     */
    private function openBudgetMargins(string $userId, PeriodComparator $period): array
    {
        return BudgetPeriod::query()
            ->whereHas('budget', fn (Builder $query) => $query->where('user_id', $userId)->whereNull('archived_at'))
            // Compared as plain dates, as in BudgetTransactionService: a Carbon
            // binding carries a time and misses the period's last day on SQLite.
            ->where('start_date', '<=', $period->to->toDateString())
            ->where('end_date', '>=', $period->from->toDateString())
            ->where('end_date', '>=', today()->toDateString())
            ->with('budget:id,name')
            ->withSum('budgetTransactions as spent', 'amount')
            ->orderBy('start_date')
            ->get()
            ->map(fn (BudgetPeriod $budgetPeriod): array => [
                'budget_id' => $budgetPeriod->budget_id,
                'budget_period_id' => $budgetPeriod->id,
                'name' => $budgetPeriod->budget->name,
                'start_date' => $budgetPeriod->start_date->toDateString(),
                'end_date' => $budgetPeriod->end_date->toDateString(),
                'allocated' => $budgetPeriod->allocated_amount,
                'spent' => (int) $budgetPeriod->spent,
                // Same as BudgetPeriod::remainingAmount(), without a query per row.
                'margin' => $budgetPeriod->allocated_amount - (int) $budgetPeriod->spent,
            ])
            ->values()
            ->all();
    }

    /**
     * Expense-side spending in the range on what any budget tracks, in the
     * user's currency and converted transaction by transaction exactly as
     * {@see CashflowSummaryService} converts `expense`, so it is an exact part
     * of it. Catch-all budgets are left out: they track whatever nothing else
     * does, which would leave no fixed expenses at all.
     */
    private function variableExpense(string $userId, string $userCurrency, PeriodComparator $period): int
    {
        $tracked = $this->trackedIds($userId, $period);

        if ($tracked['categories'] === [] && $tracked['labels'] === []) {
            return 0;
        }

        $transactions = Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$period->from, $period->to])
            ->countingTowardsTotals()
            ->where(fn (Builder $query) => $query
                ->whereIn('transactions.category_id', $tracked['categories'])
                ->orWhereHas('labels', fn (Builder $labelQuery) => $labelQuery->whereIn('labels.id', $tracked['labels'])))
            ->with(['account', 'category'])
            ->get()
            ->filter(fn (Transaction $transaction): bool => $transaction->isExpenseSide());

        $this->preloadExchangeRates($transactions, $userCurrency);

        return -$this->sumConvertedAmounts($transactions, $userCurrency);
    }

    /**
     * Categories (children included) and labels tracked by the user's budgets
     * with a period in the range.
     *
     * @return array{categories: array<int, string>, labels: array<int, string>}
     */
    private function trackedIds(string $userId, PeriodComparator $period): array
    {
        $budgets = Budget::query()
            ->notArchived()
            ->where('user_id', $userId)
            ->where('is_catch_all', false)
            ->whereHas('periods', fn (Builder $query) => $query
                ->where('start_date', '<=', $period->to->toDateString())
                ->where('end_date', '>=', $period->from->toDateString()))
            ->with('categories:id', 'labels:id')
            ->get();

        return [
            'categories' => $this->tree->expand($userId, $budgets->flatMap(fn (Budget $budget) => $budget->categories->pluck('id'))->all()),
            'labels' => $budgets->flatMap(fn (Budget $budget) => $budget->labels->pluck('id'))->unique()->values()->all(),
        ];
    }
}
