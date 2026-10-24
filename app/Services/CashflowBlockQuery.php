<?php

namespace App\Services;

use App\Enums\BudgetExpenseType;
use App\Enums\CashflowBlock;
use App\Enums\CategoryType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Which transactions make up each block of a cashflow period. The cashflow
 * summary adds up these same rows and the transactions list shows them, so a
 * block's figure and its list cannot disagree.
 *
 * @phpstan-type TrackedIds array{categories: array<int, string>, labels: array<int, string>}
 */
class CashflowBlockQuery
{
    /**
     * Tracked ids already resolved, keyed by user and period.
     *
     * @var array<string, array<string, TrackedIds>>
     */
    private array $trackedCache = [];

    public function __construct(private CategoryTree $tree) {}

    /**
     * Narrow $query to the transactions the cashflow summary counts for
     * $block over $period: rows counting towards totals, on the expense side
     * for the expense blocks, and set aside into the block's category type
     * for the saved and invested blocks.
     *
     * The expense blocks follow the budgets with a period in the range:
     * `fixed` is what a fixed budget tracks, `variable` what a variable one
     * tracks and no fixed one does (fixed wins an overlap), and `general`
     * whatever no budget tracks. Catch-all budgets are left out: they track
     * whatever nothing else does, which would leave no general expenses at all.
     *
     * The condition runs as an id subquery, so it composes with any other
     * filter, pagination or bulk update without joining into $query.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function apply(Builder $query, CashflowBlock $block, string $userId, PeriodComparator $period): Builder
    {
        $rows = $this->ownedInPeriod(Transaction::query(), $userId, $period)
            ->countingTowardsTotals()
            ->select('transactions.id');

        $tracked = fn (BudgetExpenseType $type): array => $this->trackedIds($userId, $period)[$type->value];

        match ($block) {
            CashflowBlock::Saved => $rows->setAsideInto(CategoryType::Savings),
            CashflowBlock::Invested => $rows->setAsideInto(CategoryType::Investment),
            CashflowBlock::Fixed => $this->whereTrackedBy($rows->expenseSide(), $tracked(BudgetExpenseType::Fixed)),
            CashflowBlock::Variable => $this->whereNotTrackedBy(
                $this->whereTrackedBy($rows->expenseSide(), $tracked(BudgetExpenseType::Variable)),
                $tracked(BudgetExpenseType::Fixed),
            ),
            CashflowBlock::General => $this->whereNotTrackedBy(
                $this->whereNotTrackedBy($rows->expenseSide(), $tracked(BudgetExpenseType::Fixed)),
                $tracked(BudgetExpenseType::Variable),
            ),
        };

        // Wrapped in a derived table, which MySQL materializes, so a bulk
        // update can filter on the same table it writes to. MySQL then probes
        // that set once per outer row, so the outer query repeats the owner and
        // range to read only those rows by index instead of the whole table.
        return $this->ownedInPeriod($query, $userId, $period)
            ->whereIn('transactions.id', DB::query()->fromSub($rows, 'cashflow_block_rows')->select('id'));
    }

    /**
     * Whether $block is empty by construction over $period: a fixed or
     * variable block when no budget of that type tracks anything in it. Lets
     * a caller skip the query altogether.
     */
    public function isTriviallyEmpty(CashflowBlock $block, string $userId, PeriodComparator $period): bool
    {
        $type = match ($block) {
            CashflowBlock::Fixed => BudgetExpenseType::Fixed,
            CashflowBlock::Variable => BudgetExpenseType::Variable,
            default => null,
        };

        if ($type === null) {
            return false;
        }

        $tracked = $this->trackedIds($userId, $period)[$type->value];

        return $tracked['categories'] === [] && $tracked['labels'] === [];
    }

    /**
     * The user's rows dated in the period: with the soft-delete scope's
     * `deleted_at is null`, the leading columns of `idx_transactions_budget_lookup`.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    private function ownedInPeriod(Builder $query, string $userId, PeriodComparator $period): Builder
    {
        return $query
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$period->from, $period->to]);
    }

    /**
     * @param  Builder<Transaction>  $rows
     * @param  TrackedIds  $tracked
     * @return Builder<Transaction>
     */
    private function whereTrackedBy(Builder $rows, array $tracked): Builder
    {
        return $rows->where(fn (Builder $query) => $query
            ->whereIn('transactions.category_id', $tracked['categories'])
            ->orWhereHas('labels', fn (Builder $labelQuery) => $labelQuery->whereIn('labels.id', $tracked['labels'])));
    }

    /**
     * Spelled out rather than as the negation of whereTrackedBy(): an
     * uncategorized row's `category_id IN (...)` is NULL, and negating NULL
     * would drop it instead of keeping it.
     *
     * @param  Builder<Transaction>  $rows
     * @param  TrackedIds  $tracked
     * @return Builder<Transaction>
     */
    private function whereNotTrackedBy(Builder $rows, array $tracked): Builder
    {
        return $rows
            ->where(fn (Builder $query) => $query
                ->whereNull('transactions.category_id')
                ->orWhereNotIn('transactions.category_id', $tracked['categories']))
            ->whereDoesntHave('labels', fn (Builder $labelQuery) => $labelQuery->whereIn('labels.id', $tracked['labels']));
    }

    /**
     * Categories (children included) and labels tracked by the user's
     * non-catch-all budgets with a period in the range, by expense type.
     *
     * @return array<string, TrackedIds>
     */
    private function trackedIds(string $userId, PeriodComparator $period): array
    {
        $key = $userId.'|'.$period->from->toDateString().'|'.$period->to->toDateString();

        // Archived budgets included: they still tracked the periods they had,
        // and archiving one must not turn its past spending into general costs.
        return $this->trackedCache[$key] ??= Budget::query()
            ->where('user_id', $userId)
            ->where('is_catch_all', false)
            ->whereHas('periods', fn (Builder $query) => $query->whereIn(
                'budget_periods.id',
                BudgetPeriod::query()->overlapping($period->from, $period->to)->select('id'),
            ))
            ->with('categories:id', 'labels:id')
            ->get()
            ->groupBy(fn (Budget $budget): string => $budget->expense_type->value)
            ->pipe(fn (Collection $budgets): array => collect(BudgetExpenseType::cases())
                ->mapWithKeys(fn (BudgetExpenseType $type): array => [$type->value => $this->trackedBy($userId, $budgets->get($type->value, collect()))])
                ->all());
    }

    /**
     * @param  Collection<int, Budget>  $budgets
     * @return TrackedIds
     */
    private function trackedBy(string $userId, Collection $budgets): array
    {
        return [
            'categories' => $this->tree->expand($userId, $budgets->flatMap(fn (Budget $budget) => $budget->categories->pluck('id'))->all()),
            'labels' => $budgets->flatMap(fn (Budget $budget) => $budget->labels->pluck('id'))->unique()->values()->all(),
        ];
    }
}
