<?php

namespace App\Services;

use App\Enums\CategoryType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BudgetTransactionService
{
    public function __construct(private readonly CategoryTree $tree = new CategoryTree) {}

    public function assignTransaction(Transaction $transaction): void
    {
        $userId = $transaction->user_id;

        if (! $userId) {
            return;
        }

        // Ensure labels are available for matching (safe if already loaded).
        $transaction->loadMissing('labels');

        $transactionLabelIds = $transaction->labels->pluck('id');

        // A budget tracking a parent category also covers its children, so a
        // transaction matches a budget when any of its category's ancestors
        // (or itself) is attached to that budget.
        $categoryMatchIds = $transaction->category_id
            ? $this->tree->ancestorAndSelfIds($userId, $transaction->category_id)
            : [];

        // Find budget periods that potentially match this transaction.
        $budgetPeriods = BudgetPeriod::query()
            ->whereHas('budget', function ($query) use ($categoryMatchIds, $transactionLabelIds, $userId) {
                $query->where('user_id', $userId)
                    ->where(function ($q) use ($categoryMatchIds, $transactionLabelIds) {
                        $q->whereHas('categories', function ($cq) use ($categoryMatchIds) {
                            $cq->whereIn('categories.id', $categoryMatchIds);
                        })
                            ->orWhereHas('labels', function ($lq) use ($transactionLabelIds) {
                                $lq->whereIn('labels.id', $transactionLabelIds);
                            });
                    });
            })
            ->where('start_date', '<=', $transaction->transaction_date)
            ->where('end_date', '>=', $transaction->transaction_date)
            ->with('budget.categories:id', 'budget.labels:id')
            ->get();

        // Narrow down to periods whose budget actually matches the transaction.
        $matchingPeriodIds = [];

        foreach ($budgetPeriods as $period) {
            $budget = $period->budget;

            $matchesCategory = $categoryMatchIds !== []
                && $budget->categories->pluck('id')->intersect($categoryMatchIds)->isNotEmpty();
            $matchesLabel = $budget->labels
                ->pluck('id')
                ->intersect($transactionLabelIds)
                ->isNotEmpty();

            if ($matchesCategory || $matchesLabel) {
                $matchingPeriodIds[] = $period->id;
            }
        }

        $matchingPeriodIds = array_merge(
            $matchingPeriodIds,
            $this->catchAllPeriodIds($transaction, $userId, $categoryMatchIds),
        );

        // Apply changes atomically so concurrent workers cannot leave the
        // transaction half-assigned and the unique index guards duplicates.
        DB::transaction(function () use ($transaction, $matchingPeriodIds) {
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

            foreach ($matchingPeriodIds as $periodId) {
                BudgetTransaction::updateOrCreate(
                    [
                        'transaction_id' => $transaction->id,
                        'budget_period_id' => $periodId,
                    ],
                    [
                        'amount' => -$transaction->amount,
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
        // Load the budget with its relationships
        $budget = $period->budget()->with(['categories:id', 'labels:id'])->first();

        if (! $budget) {
            return 0;
        }

        $assignedCount = 0;

        // Tracking a parent category also tracks its children's spending.
        $categoryIds = collect($this->tree->expand($budget->user_id, $budget->categories->pluck('id')->all()));
        $labelIds = $budget->labels->pluck('id');

        Log::info('Building query for historical transactions', [
            'user_id' => $budget->user_id,
            'category_ids' => $categoryIds->all(),
            'label_ids' => $labelIds->all(),
            'start_date' => $period->start_date->toDateString(),
            'end_date' => $period->end_date->toDateString(),
        ]);

        // Build the query for matching transactions
        $query = Transaction::query()
            ->where('user_id', $budget->user_id)
            ->whereBetween('transaction_date', [$period->start_date, $period->end_date])
            ->withoutTrashed();

        if ($budget->is_catch_all) {
            // A catch-all budget absorbs every expense whose category is not
            // already tracked by one of the user's other budgets.
            $claimedCategoryIds = $this->tree->expand(
                $budget->user_id,
                $this->claimedCategoryIds($budget->user_id),
            );

            $query->whereNotNull('category_id')
                ->when(
                    $claimedCategoryIds !== [],
                    fn ($q) => $q->whereNotIn('category_id', $claimedCategoryIds),
                )
                ->whereHas('category', fn ($q) => $q->where('type', CategoryType::Expense->value));
        } else {
            // Filter by any tracked category OR label
            $query->where(function ($q) use ($categoryIds, $labelIds) {
                if ($categoryIds->isNotEmpty()) {
                    $q->whereIn('category_id', $categoryIds);
                }

                if ($labelIds->isNotEmpty()) {
                    $q->orWhereHas('labels', function ($labelQuery) use ($labelIds) {
                        $labelQuery->whereIn('labels.id', $labelIds);
                    });
                }
            });
        }

        $totalCount = $query->count();
        Log::info("Found {$totalCount} transactions to process in date range");

        // Process in chunks to prevent memory issues
        $query->chunk(500, function ($transactions) use ($period, &$assignedCount) {
            foreach ($transactions as $transaction) {
                $budgetTransaction = BudgetTransaction::updateOrCreate(
                    [
                        'transaction_id' => $transaction->id,
                        'budget_period_id' => $period->id,
                    ],
                    [
                        'amount' => -$transaction->amount,
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
     * Catch-all budget periods that should absorb this transaction: an expense
     * whose category (or an ancestor) is not tracked by any non-catch-all budget.
     *
     * @param  array<int, string>  $categoryMatchIds  the transaction category and its ancestors
     * @return array<int, string>
     */
    private function catchAllPeriodIds(Transaction $transaction, string $userId, array $categoryMatchIds): array
    {
        if ($transaction->category_id === null) {
            return [];
        }

        $transaction->loadMissing('category');

        if ($transaction->category?->type !== CategoryType::Expense) {
            return [];
        }

        if (array_intersect($categoryMatchIds, $this->claimedCategoryIds($userId)) !== []) {
            return [];
        }

        return BudgetPeriod::query()
            ->whereHas('budget', function ($query) use ($userId) {
                $query->where('user_id', $userId)->where('is_catch_all', true);
            })
            ->where('start_date', '<=', $transaction->transaction_date)
            ->where('end_date', '>=', $transaction->transaction_date)
            ->pluck('id')
            ->all();
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
