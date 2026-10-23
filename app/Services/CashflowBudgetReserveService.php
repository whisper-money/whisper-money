<?php

namespace App\Services;

use App\Enums\BudgetPeriodType;
use App\Enums\CashflowBlock;
use App\Models\BudgetPeriod;
use App\Models\Transaction;
use App\Services\Concerns\ConvertsTransactionCurrency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What the user's budgets still hold back from a cashflow period, and how much
 * of its spending they cover.
 *
 * @phpstan-type BudgetMarginRow array{budget_id: string, budget_period_id: string, name: string, start_date: string, end_date: string, expense_type: string, allocated: int, spent: int, margin: int}
 * @phpstan-type ExpenseSplit array{fixed_expense: int, variable_expense: int, general_expense: int}
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
        private CashflowBlockQuery $blocks,
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
     * as fixed. See {@see self::expenseSplit()}.
     *
     * Budget amounts are read as stored, in the user's currency, the same way
     * the budget screens read them.
     *
     * @return array{reserved: int, budgets: list<BudgetMarginRow>, fixed_expense: int, variable_expense: int, general_expense: int}
     */
    public function forPeriod(string $userId, string $userCurrency, PeriodComparator $period, int $expense): array
    {
        $budgets = $this->openBudgetMargins($userId, $period);

        return [
            'reserved' => array_sum(array_map(fn (array $row): int => max(0, $row['margin']), $budgets)),
            'budgets' => $budgets,
            ...$this->expenseSplit($userId, $userCurrency, $period, $expense),
        ];
    }

    /**
     * `$expense` split by the expense type of the budgets tracking it: fixed,
     * variable, and general for what no budget tracks. The three always add up
     * to `$expense` (any can be negative in a month of refunds, as `$expense`
     * can). Unlike `reserved`, it reads as well for a past period as for the
     * current one.
     *
     * @return ExpenseSplit
     */
    public function expenseSplit(string $userId, string $userCurrency, PeriodComparator $period, int $expense): array
    {
        $budgeted = $this->budgetedExpense($userId, $userCurrency, $period);

        return [
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
            ->with('budget:id,name,expense_type')
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
                    'expense_type' => $budgetPeriod->budget->expense_type->value,
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
     * exact parts of it. The rows are the ones {@see CashflowBlockQuery} lists
     * for each block, so the transactions list adds up to the same figures.
     *
     * @return array{fixed: int, variable: int}
     */
    private function budgetedExpense(string $userId, string $userCurrency, PeriodComparator $period): array
    {
        $transactions = collect([CashflowBlock::Fixed, CashflowBlock::Variable])->mapWithKeys(
            fn (CashflowBlock $block): array => [$block->value => $this->blocks
                ->apply(Transaction::query(), $block, $userId, $period)
                ->with(['account', 'category'])
                ->get()],
        );

        $this->preloadExchangeRates($transactions->flatten(1), $userCurrency);

        return $transactions
            ->map(fn (Collection $blockTransactions): int => -$this->sumConvertedAmounts($blockTransactions, $userCurrency))
            ->all();
    }
}
