<?php

namespace App\Services;

use App\Enums\CategoryType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BudgetTransactionService
{
    public function __construct(private readonly CategoryTree $tree = new CategoryTree) {}

    /**
     * (Re)assign a transaction to the budget periods it belongs to. A split
     * transaction is attributed per line — each line matches budgets by its own
     * category or labels — and the matching lines' amounts are aggregated into a
     * single BudgetTransaction per period (the unique index allows only one row
     * per transaction+period).
     */
    public function assignTransaction(Transaction $transaction): void
    {
        $userId = $transaction->user_id;

        if (! $userId) {
            return;
        }

        $allocations = $this->allocationsFor($transaction);
        $claimedCategoryIds = $this->claimedCategoryIds($userId);

        // period_id => aggregated amount (already negated: expenses are positive
        // spend in a budget). Periods that net to zero are dropped below.
        $periodAmounts = [];

        foreach ($this->candidatePeriods($transaction, $userId, $allocations) as $period) {
            $amount = $this->matchedAmountForBudget($allocations, $period->budget, $userId, $claimedCategoryIds);

            if ($amount !== 0) {
                $periodAmounts[$period->id] = $amount;
            }
        }

        $matchingPeriodIds = array_keys($periodAmounts);

        // Apply changes atomically so concurrent workers cannot leave the
        // transaction half-assigned and the unique index guards duplicates.
        DB::transaction(function () use ($transaction, $periodAmounts, $matchingPeriodIds) {
            Transaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->first();

            BudgetTransaction::query()
                ->where('transaction_id', $transaction->id)
                ->when(
                    $matchingPeriodIds !== [],
                    fn ($q) => $q->whereNotIn('budget_period_id', $matchingPeriodIds),
                )
                ->delete();

            foreach ($periodAmounts as $periodId => $amount) {
                BudgetTransaction::updateOrCreate(
                    [
                        'transaction_id' => $transaction->id,
                        'budget_period_id' => $periodId,
                    ],
                    [
                        'amount' => $amount,
                    ],
                );
            }
        }, attempts: 5);
    }

    public function unassignTransaction(Transaction $transaction): void
    {
        BudgetTransaction::where('transaction_id', $transaction->id)->delete();
    }

    public function assignHistoricalTransactionsToPeriod(BudgetPeriod $period): int
    {
        $budget = $period->budget()->with(['categories:id', 'labels:id'])->first();

        if (! $budget) {
            return 0;
        }

        $claimedCategoryIds = $this->claimedCategoryIds($budget->user_id);
        $assignedCount = 0;

        // Evaluate every transaction in range against this budget. Split-aware
        // matching lives on the lines, so we can't pre-filter by the parent's
        // category_id/labels in SQL.
        // ponytail: full period scan (chunked); narrow to matched-or-split rows
        // if a large backfill becomes slow.
        Transaction::query()
            ->where('user_id', $budget->user_id)
            ->whereBetween('transaction_date', [$period->start_date, $period->end_date])
            ->withoutTrashed()
            ->with(['category:id,type', 'labels:id', 'splits.category:id,type', 'splits.labels:id'])
            ->chunk(500, function (Collection $transactions) use ($period, $budget, $claimedCategoryIds, &$assignedCount): void {
                foreach ($transactions as $transaction) {
                    $amount = $this->matchedAmountForBudget(
                        $this->allocationsFor($transaction),
                        $budget,
                        $budget->user_id,
                        $claimedCategoryIds,
                    );

                    if ($amount === 0) {
                        continue;
                    }

                    $budgetTransaction = BudgetTransaction::updateOrCreate(
                        [
                            'transaction_id' => $transaction->id,
                            'budget_period_id' => $period->id,
                        ],
                        [
                            'amount' => $amount,
                        ],
                    );

                    if ($budgetTransaction->wasRecentlyCreated) {
                        $assignedCount++;
                    }
                }
            });

        return $assignedCount;
    }

    /**
     * The transaction's category attributions: one entry for an unsplit
     * transaction, one per line for a split one.
     *
     * @return array<int, array{categoryId: ?string, categoryType: ?CategoryType, labelIds: array<int, string>, amount: int}>
     */
    private function allocationsFor(Transaction $transaction): array
    {
        if ($transaction->isSplit()) {
            $transaction->loadMissing(['splits.category', 'splits.labels']);

            return $transaction->splits->map(fn ($line): array => [
                'categoryId' => $line->category_id,
                'categoryType' => $line->category?->type,
                'labelIds' => $line->labels->pluck('id')->all(),
                'amount' => $line->amount,
            ])->all();
        }

        $transaction->loadMissing(['category', 'labels']);

        return [[
            'categoryId' => $transaction->category_id,
            'categoryType' => $transaction->category?->type,
            'labelIds' => $transaction->labels->pluck('id')->all(),
            'amount' => $transaction->amount,
        ]];
    }

    /**
     * Budget periods in the transaction's date range that could match any of its
     * allocations — by tracked category, tracked label, or being a catch-all.
     *
     * @param  array<int, array{categoryId: ?string, categoryType: ?CategoryType, labelIds: array<int, string>, amount: int}>  $allocations
     * @return Collection<int, BudgetPeriod>
     */
    private function candidatePeriods(Transaction $transaction, string $userId, array $allocations): Collection
    {
        $categoryMatchIds = [];
        $labelIds = [];

        foreach ($allocations as $allocation) {
            if ($allocation['categoryId'] !== null) {
                $categoryMatchIds = array_merge($categoryMatchIds, $this->tree->ancestorAndSelfIds($userId, $allocation['categoryId']));
            }

            $labelIds = array_merge($labelIds, $allocation['labelIds']);
        }

        $categoryMatchIds = array_values(array_unique($categoryMatchIds));
        $labelIds = array_values(array_unique($labelIds));

        return BudgetPeriod::query()
            ->whereHas('budget', function ($query) use ($userId, $categoryMatchIds, $labelIds) {
                $query->where('user_id', $userId)
                    ->where(function ($inner) use ($categoryMatchIds, $labelIds) {
                        $inner->where('is_catch_all', true)
                            ->orWhereHas('categories', fn ($cq) => $cq->whereIn('categories.id', $categoryMatchIds))
                            ->orWhereHas('labels', fn ($lq) => $lq->whereIn('labels.id', $labelIds));
                    });
            })
            ->where('start_date', '<=', $transaction->transaction_date)
            ->where('end_date', '>=', $transaction->transaction_date)
            ->with('budget.categories:id', 'budget.labels:id')
            ->get();
    }

    /**
     * The portion of a transaction's amount that belongs to a budget: the sum of
     * the allocations that match it (negated so expenses count as positive
     * spend), or zero when none match.
     *
     * @param  array<int, array{categoryId: ?string, categoryType: ?CategoryType, labelIds: array<int, string>, amount: int}>  $allocations
     * @param  array<int, string>  $claimedCategoryIds
     */
    private function matchedAmountForBudget(array $allocations, Budget $budget, string $userId, array $claimedCategoryIds): int
    {
        $total = 0;

        foreach ($allocations as $allocation) {
            if ($this->allocationMatchesBudget($allocation, $budget, $userId, $claimedCategoryIds)) {
                $total += -$allocation['amount'];
            }
        }

        return $total;
    }

    /**
     * @param  array{categoryId: ?string, categoryType: ?CategoryType, labelIds: array<int, string>, amount: int}  $allocation
     * @param  array<int, string>  $claimedCategoryIds
     */
    private function allocationMatchesBudget(array $allocation, Budget $budget, string $userId, array $claimedCategoryIds): bool
    {
        if ($budget->is_catch_all) {
            return $this->allocationHitsCatchAll($allocation, $userId, $claimedCategoryIds);
        }

        $categoryMatchIds = $allocation['categoryId'] !== null
            ? $this->tree->ancestorAndSelfIds($userId, $allocation['categoryId'])
            : [];

        $matchesCategory = $categoryMatchIds !== []
            && $budget->categories->pluck('id')->intersect($categoryMatchIds)->isNotEmpty();

        $matchesLabel = $budget->labels
            ->pluck('id')
            ->intersect($allocation['labelIds'])
            ->isNotEmpty();

        return $matchesCategory || $matchesLabel;
    }

    /**
     * A catch-all budget absorbs an expense allocation whose category (or an
     * ancestor) is not tracked by any of the user's other budgets.
     *
     * @param  array{categoryId: ?string, categoryType: ?CategoryType, labelIds: array<int, string>, amount: int}  $allocation
     * @param  array<int, string>  $claimedCategoryIds
     */
    private function allocationHitsCatchAll(array $allocation, string $userId, array $claimedCategoryIds): bool
    {
        if ($allocation['categoryId'] === null || $allocation['categoryType'] !== CategoryType::Expense) {
            return false;
        }

        $categoryMatchIds = $this->tree->ancestorAndSelfIds($userId, $allocation['categoryId']);

        return array_intersect($categoryMatchIds, $claimedCategoryIds) === [];
    }

    /**
     * Category ids directly tracked by the user's non-catch-all budgets.
     *
     * @return array<int, string>
     */
    private function claimedCategoryIds(string $userId): array
    {
        return Budget::query()
            ->where('user_id', $userId)
            ->where('is_catch_all', false)
            ->with('categories:id')
            ->get()
            ->flatMap(fn (Budget $budget) => $budget->categories->pluck('id'))
            ->unique()
            ->values()
            ->all();
    }
}
