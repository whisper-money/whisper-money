<?php

namespace App\Services;

use App\Enums\BudgetExpenseType;
use App\Enums\BudgetPeriodType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Transaction;
use App\Services\Concerns\ConvertsTransactionCurrency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What the user's budgets still hold back from a cashflow period, and how much
 * of its spending they cover.
 *
 * @phpstan-type BudgetMarginRow array{budget_id: string, budget_period_id: string, name: string, start_date: string, end_date: string, allocated: int, spent: int, margin: int}
 */
class CashflowBudgetReserveService
{
    use ConvertsTransactionCurrency;

    /**
     * The longest a quarter gets; any longer range is read as a year.
     */
    private const MAX_QUARTER_DAYS = 92;

    public function __construct(
        private ExchangeRateService $exchangeRateService,
        private CategoryTree $tree,
    ) {}

    /**
     * `reserved` adds up the positive margins of the budget periods that
     * overlap the range and are in progress today: money still earmarked for
     * spending. An over-budget period adds nothing rather than eating into the
     * rest, a closed one adds nothing because whatever it left over is already
     * back in the net, and a future one adds nothing because none of this
     * range's income is promised to it yet. `budgets` lists the same periods,
     * with margins that may be negative. A margin, like the budget screens,
     * ignores any carried-over amount.
     *
     * Both only count the budgets whose period fits the range: weekly,
     * bi-weekly and monthly budgets for a range of up to a quarter (the month
     * and quarter views), yearly ones for anything longer (the year view). A
     * yearly budget's margin is not money held back from one month's income,
     * and a monthly one's says little about a whole year.
     *
     * `fixed_expense` is the part of `$expense` booked to categories (or
     * their children) and labels tracked by a budget marked fixed with a
     * period in the range, `variable_expense` the part tracked by one marked
     * variable, and `general_expense` the rest: spending no budget tracks. A
     * transaction tracked by both a fixed and a variable budget counts once,
     * as fixed. The three always add up to `$expense` (any can be negative in
     * a month of refunds, as `$expense` can).
     *
     * Budget amounts are read as stored, in the user's currency, the same way
     * the budget screens read them.
     *
     * @return array{reserved: int, budgets: list<BudgetMarginRow>, fixed_expense: int, variable_expense: int, general_expense: int}
     */
    public function forPeriod(string $userId, string $userCurrency, PeriodComparator $period, int $expense): array
    {
        $budgets = $this->openBudgetMargins($userId, $period);
        $budgeted = $this->budgetedExpense($userId, $userCurrency, $period);

        return [
            'reserved' => array_sum(array_map(fn (array $row): int => max(0, $row['margin']), $budgets)),
            'budgets' => $budgets,
            'fixed_expense' => $budgeted['fixed'],
            'variable_expense' => $budgeted['variable'],
            'general_expense' => $expense - $budgeted['fixed'] - $budgeted['variable'],
        ];
    }

    /**
     * @return list<BudgetMarginRow>
     */
    private function openBudgetMargins(string $userId, PeriodComparator $period): array
    {
        return BudgetPeriod::query()
            ->whereHas('budget', fn (Builder $query) => $query
                ->where('user_id', $userId)
                ->whereNull('archived_at')
                ->whereIn('period_type', $this->periodTypesFor($period)))
            ->overlapping($period->from, $period->to)
            // In progress today: a period that is over already gave back what it
            // left, and one yet to start holds nothing back from this income.
            ->where('start_date', '<=', today()->toDateString())
            ->where('end_date', '>=', today()->toDateString())
            ->with('budget:id,name')
            ->withSum('budgetTransactions as spent', 'amount')
            ->orderBy('start_date')
            ->get()
            ->map(function (BudgetPeriod $budgetPeriod): array {
                $spent = (int) $budgetPeriod->getAttribute('spent');

                return [
                    'budget_id' => $budgetPeriod->budget_id,
                    'budget_period_id' => $budgetPeriod->id,
                    'name' => $budgetPeriod->budget->name,
                    'start_date' => $budgetPeriod->start_date->toDateString(),
                    'end_date' => $budgetPeriod->end_date->toDateString(),
                    'allocated' => $budgetPeriod->allocated_amount,
                    'spent' => $spent,
                    // Same as BudgetPeriod::remainingAmount(), without a query per row.
                    'margin' => $budgetPeriod->allocated_amount - $spent,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The budget period types whose margins a range of this length reserves.
     *
     * @return list<BudgetPeriodType>
     */
    private function periodTypesFor(PeriodComparator $period): array
    {
        if ($period->days() > self::MAX_QUARTER_DAYS) {
            return [BudgetPeriodType::Yearly];
        }

        return [BudgetPeriodType::Weekly, BudgetPeriodType::Biweekly, BudgetPeriodType::Monthly];
    }

    /**
     * Expense-side spending in the range on what fixed and variable budgets
     * track, in the user's currency and converted transaction by transaction
     * exactly as {@see CashflowSummaryService} converts `expense`, so both are
     * exact parts of it. Catch-all budgets are left out: they track whatever
     * nothing else does, which would leave no general expenses at all.
     *
     * @return array{fixed: int, variable: int}
     */
    private function budgetedExpense(string $userId, string $userCurrency, PeriodComparator $period): array
    {
        $tracked = $this->trackedIds($userId, $period);
        $trackedByFixed = $tracked[BudgetExpenseType::Fixed->value];
        $trackedByVariable = $tracked[BudgetExpenseType::Variable->value];
        $categories = [...$trackedByFixed['categories'], ...$trackedByVariable['categories']];
        $labels = [...$trackedByFixed['labels'], ...$trackedByVariable['labels']];

        if ($categories === [] && $labels === []) {
            return ['fixed' => 0, 'variable' => 0];
        }

        $transactions = Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$period->from, $period->to])
            ->countingTowardsTotals()
            ->where(fn (Builder $query) => $query
                ->whereIn('transactions.category_id', $categories)
                ->orWhereHas('labels', fn (Builder $labelQuery) => $labelQuery->whereIn('labels.id', $labels)))
            ->with(['account', 'category', 'labels:id'])
            ->get()
            ->filter(fn (Transaction $transaction): bool => $transaction->isExpenseSide());

        $this->preloadExchangeRates($transactions, $userCurrency);

        // Fixed wins an overlap, so a transaction is only ever counted once.
        [$fixed, $variable] = $transactions->partition(
            fn (Transaction $transaction): bool => $this->isTrackedBy($transaction, $trackedByFixed),
        );

        return [
            'fixed' => -$this->sumConvertedAmounts($fixed, $userCurrency),
            'variable' => -$this->sumConvertedAmounts($variable, $userCurrency),
        ];
    }

    /**
     * @param  array{categories: array<int, string>, labels: array<int, string>}  $tracked
     */
    private function isTrackedBy(Transaction $transaction, array $tracked): bool
    {
        return in_array($transaction->category_id, $tracked['categories'], true)
            || $transaction->labels->pluck('id')->intersect($tracked['labels'])->isNotEmpty();
    }

    /**
     * Categories (children included) and labels tracked by the user's
     * non-catch-all budgets with a period in the range, by expense type.
     *
     * @return array<string, array{categories: array<int, string>, labels: array<int, string>}>
     */
    private function trackedIds(string $userId, PeriodComparator $period): array
    {
        // Archived budgets included: they still tracked the periods they had,
        // and archiving one must not turn its past spending into general costs.
        $budgets = Budget::query()
            ->where('user_id', $userId)
            ->where('is_catch_all', false)
            ->whereHas('periods', fn (Builder $query) => $query->whereIn(
                'budget_periods.id',
                BudgetPeriod::query()->overlapping($period->from, $period->to)->select('id'),
            ))
            ->with('categories:id', 'labels:id')
            ->get()
            ->groupBy(fn (Budget $budget): string => $budget->expense_type->value);

        return collect(BudgetExpenseType::cases())
            ->mapWithKeys(fn (BudgetExpenseType $type): array => [$type->value => $this->trackedBy($userId, $budgets->get($type->value, collect()))])
            ->all();
    }

    /**
     * @param  Collection<int, Budget>  $budgets
     * @return array{categories: array<int, string>, labels: array<int, string>}
     */
    private function trackedBy(string $userId, Collection $budgets): array
    {
        return [
            'categories' => $this->tree->expand($userId, $budgets->flatMap(fn (Budget $budget) => $budget->categories->pluck('id'))->all()),
            'labels' => $budgets->flatMap(fn (Budget $budget) => $budget->labels->pluck('id'))->unique()->values()->all(),
        ];
    }
}
