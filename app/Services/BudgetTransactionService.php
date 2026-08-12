<?php

namespace App\Services;

use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BudgetTransactionService
{
    public function __construct(
        private readonly CategoryTree $tree = new CategoryTree,
        private readonly BudgetNotificationService $notifications = new BudgetNotificationService,
    ) {}

    /**
     * @param  bool  $notify  set to false for bulk backfills, where the emails
     *                        would describe budget states the user never crossed
     */
    public function assignTransaction(Transaction $transaction, bool $notify = true): void
    {
        $userId = $transaction->user_id;

        if (! $userId) {
            return;
        }

        // Ensure labels are available for matching, and the account for the
        // ownership share (both safe if already loaded). The account is loaded
        // with trashed ones too, so this agrees with the SQL re-weigh, which
        // ignores the soft-delete scope as well.
        $transaction->loadMissing('labels');
        $transaction->loadMissing(['account' => fn ($query) => $query->withTrashed()]);

        $matchingPeriodIds = $this->trackedPeriodIds($transaction, $userId);

        // A catch-all budget only absorbs what nothing else counts. Any budget
        // already tracking this transaction — by category or by label — in a
        // period covering its date takes precedence, so the catch-all steps in
        // only when there is no such period.
        if ($matchingPeriodIds === []) {
            $matchingPeriodIds = $this->catchAllPeriodIds($transaction);
        }

        // Apply changes atomically so concurrent workers cannot leave the
        // transaction half-assigned and the unique index guards duplicates.
        $createdPeriodIds = [];

        DB::transaction(function () use ($transaction, $matchingPeriodIds, &$createdPeriodIds) {
            // Reset per attempt: a deadlock retry re-runs this closure.
            $createdPeriodIds = [];

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
                $budgetTransaction = $this->recordSnapshot($transaction, $periodId);

                if ($budgetTransaction->wasRecentlyCreated) {
                    $createdPeriodIds[] = $periodId;
                }
            }
        }, attempts: 5);

        if ($notify) {
            $this->notifications->handleAssignment($transaction, $matchingPeriodIds, $createdPeriodIds);
        }
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
            // The owning account weighs every snapshot; eager loaded so a
            // 500-row chunk does not turn into 500 account lookups, and with
            // trashed ones so it agrees with the SQL re-weigh.
            ->with(['account' => fn ($query) => $query->withTrashed()])
            ->withoutTrashed();

        if ($budget->is_catch_all) {
            $this->applyCatchAllFilters($query, $period, $budget->user_id);
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
                if ($this->recordSnapshot($transaction, $period->id)->wasRecentlyCreated) {
                    $assignedCount++;
                }
            }
        });

        return $assignedCount;
    }

    /**
     * Record what a transaction contributes to a budget period: the owner's
     * share of it, flipped so an expense counts as positive spending.
     *
     * The amount is a snapshot taken here and never revisited, so a later
     * change to the account's share has to go through
     * {@see self::reweighAccountSnapshots()}.
     */
    private function recordSnapshot(Transaction $transaction, string $budgetPeriodId): BudgetTransaction
    {
        return BudgetTransaction::updateOrCreate(
            [
                'transaction_id' => $transaction->id,
                'budget_period_id' => $budgetPeriodId,
            ],
            [
                'amount' => -$transaction->ownerShareOf($transaction->amount),
            ],
        );
    }

    /**
     * Re-snapshot every budget row of an account after its ownership share
     * changed, in SQL so it stays one query no matter how much history the
     * account has. Uses {@see Transaction::OWNED_AMOUNT_SQL} so the rounding
     * matches what {@see self::recordSnapshot()} would have written.
     *
     * @return int the number of rows re-weighed
     */
    public function reweighAccountSnapshots(Account $account): int
    {
        $reweighed = DB::table('budget_transactions')
            ->join('transactions', 'transactions.id', '=', 'budget_transactions.transaction_id')
            ->join('accounts', 'accounts.id', '=', 'transactions.account_id')
            ->where('accounts.id', $account->id)
            ->update(['budget_transactions.amount' => DB::raw('-('.Transaction::OWNED_AMOUNT_SQL.')')]);

        // Every affected period now holds a different total, so the limit alerts
        // it already sent describe a state that no longer exists. Clearing the
        // flags lets the next crossing notify again, the same way a refund that
        // drops a budget back under its limit does.
        if ($reweighed > 0) {
            BudgetPeriod::query()
                ->whereHas(
                    'budgetTransactions.transaction',
                    fn (Builder $query) => $query->where('account_id', $account->id),
                )
                ->update(['close_to_limit_notified' => false, 'over_limit_notified' => false]);
        }

        return $reweighed;
    }

    /**
     * Narrow a transaction query to what a catch-all budget absorbs: expenses
     * whose category and labels are not already tracked by another budget.
     *
     * @param  Builder<Transaction>  $query
     */
    private function applyCatchAllFilters(Builder $query, BudgetPeriod $period, string $userId): void
    {
        $claimed = $this->claimedIds($userId, $period);
        $claimedCategoryIds = $this->tree->expand($userId, $claimed['categories']);

        $query->whereNotNull('category_id')
            ->when(
                $claimedCategoryIds !== [],
                fn ($q) => $q->whereNotIn('category_id', $claimedCategoryIds),
            )
            ->when(
                $claimed['labels'] !== [],
                fn ($q) => $q->whereDoesntHave(
                    'labels',
                    fn ($labelQuery) => $labelQuery->whereIn('labels.id', $claimed['labels']),
                ),
            )
            ->whereHas('category', fn ($q) => $q->where('type', CategoryType::Expense->value));
    }

    /**
     * Budget periods that track this transaction by category or by label and
     * cover its date.
     *
     * @return array<int, string>
     */
    private function trackedPeriodIds(Transaction $transaction, string $userId): array
    {
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

        return $matchingPeriodIds;
    }

    /**
     * Catch-all budget periods that should absorb this expense.
     *
     * @return array<int, string>
     */
    private function catchAllPeriodIds(Transaction $transaction): array
    {
        if ($transaction->category_id === null) {
            return [];
        }

        $transaction->loadMissing('category');

        if ($transaction->category?->type !== CategoryType::Expense) {
            return [];
        }

        return BudgetPeriod::query()
            ->whereHas('budget', function ($query) use ($transaction) {
                $query->where('user_id', $transaction->user_id)->where('is_catch_all', true);
            })
            ->where('start_date', '<=', $transaction->transaction_date)
            ->where('end_date', '>=', $transaction->transaction_date)
            ->pluck('id')
            ->all();
    }

    /**
     * Categories and labels tracked by the user's other budgets over the same
     * stretch of time as the given catch-all period.
     *
     * A budget only claims spending it actually counts, so one whose periods do
     * not reach this far back leaves its categories and labels unclaimed here —
     * otherwise the catch-all would drop those expenses without any budget
     * picking them up.
     *
     * @return array{categories: array<int, string>, labels: array<int, string>}
     */
    private function claimedIds(string $userId, BudgetPeriod $period): array
    {
        $budgets = Budget::query()
            ->where('user_id', $userId)
            ->where('is_catch_all', false)
            ->whereHas('periods', function ($query) use ($period) {
                $query->where('start_date', '<=', $period->end_date)
                    ->where('end_date', '>=', $period->start_date);
            })
            ->with('categories:id', 'labels:id')
            ->get();

        return [
            'categories' => $budgets->flatMap(fn (Budget $budget) => $budget->categories->pluck('id'))->unique()->values()->all(),
            'labels' => $budgets->flatMap(fn (Budget $budget) => $budget->labels->pluck('id'))->unique()->values()->all(),
        ];
    }
}
