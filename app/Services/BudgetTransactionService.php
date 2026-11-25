<?php

namespace App\Services;

use App\Enums\CategoryType;
use App\Jobs\ReassignTransactionsToBudgets;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Transaction;
use App\Services\Budgets\BudgetAssignmentLookups;
use App\Services\Budgets\SplitBudgetRows;
use App\Services\Transactions\EffectiveTransactionPostings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type LeftAlone array{transaction_id: string, transaction_date: string, amount: int, reason: string}
 * @phpstan-type UnprovedChange array{transaction_id: string, transaction_date: string, from: int, to: int}
 * @phpstan-type CatchAllChanges array{released_double_count: int, reduced_double_count: int, released_other: int, adjusted: int, absorbed: int, spent_change: int, left_alone: array<int, LeftAlone>, unproved_changes: array<int, UnprovedChange>}
 * @phpstan-type CatchAllVerdict array{amount: int, reason: ?string}
 * @phpstan-type SnapshotChanges array{rows: array<int, array{transaction_id: string, budget_period_id: string, amount: int}>, released_ids: array<int, string>, previous_amounts: array<string, int>, absorbed: int, adjusted: int, spent_change: int}
 */
class BudgetTransactionService
{
    /** Another budget's row proves the catch-all counts this as well. */
    private const string DOUBLE_COUNT = 'double_count';

    /** A budget tracks this today but holds no row for it, so taking it out of the catch-all could leave it counted nowhere. */
    public const string CLAIMED_NOT_HELD = 'claimed_not_held';

    /** A budget's row does not match what that budget tracks today, so nothing proves which part of the transaction it counts. */
    public const string HOLDER_ROW_UNEXPLAINED = 'holder_row_unexplained';

    /** The same, for a transaction the catch-all holds no row for: there is nothing to leave alone, it just is not absorbed. */
    public const string NOT_ABSORBED_UNEXPLAINED = 'not_absorbed_unexplained';

    public function __construct(
        private readonly CategoryTree $tree = new CategoryTree,
        private readonly EffectiveTransactionPostings $postings = new EffectiveTransactionPostings,
        private readonly BudgetNotificationService $notifications = new BudgetNotificationService,
    ) {}

    /**
     * @param  bool  $notify  set to false for bulk backfills, where the emails
     *                        would describe budget states the user never crossed
     */
    public function assignTransaction(Transaction $transaction, bool $notify = true): void
    {
        $this->assignWithLookups($transaction, $notify, new BudgetAssignmentLookups($this->tree));
    }

    /**
     * {@see self::assignTransaction()} for a batch, reading each user's
     * category tree once for the whole batch instead of once per transaction.
     * Each transaction is still re-read under its own row lock, and the periods
     * covering its date and the budgets that can displace a catch-all are read
     * inside that lock (see {@see BudgetAssignmentLookups}).
     *
     * The lookups only live for this call, so the next batch sees any category
     * re-parented in the meantime.
     *
     * @param  iterable<int, Transaction>  $transactions
     * @param  bool  $notify  set to false for bulk backfills, where the emails
     *                        would describe budget states the user never crossed
     */
    public function assignTransactions(iterable $transactions, bool $notify = true): void
    {
        $lookups = new BudgetAssignmentLookups($this->tree);

        foreach ($transactions as $transaction) {
            $this->assignWithLookups($transaction, $notify, $lookups);
        }
    }

    private function assignWithLookups(Transaction $transaction, bool $notify, BudgetAssignmentLookups $lookups): void
    {
        if (! $transaction->user_id) {
            return;
        }

        $matchingPeriodIds = [];
        $createdPeriodIds = [];
        $assignable = true;

        DB::transaction(function () use ($transaction, $lookups, &$matchingPeriodIds, &$createdPeriodIds, &$assignable): void {
            // Reset per attempt: a deadlock retry re-runs the closure.
            $matchingPeriodIds = [];
            $createdPeriodIds = [];

            $locked = Transaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->with(['labels', 'category', 'splits.category'])
                ->first();

            // Deleted (or split, which soft-deletes the original) while the job
            // waited: a trashed row has nothing to assign, and its delete
            // already queued taking it out of its budgets.
            $assignable = $locked !== null;

            if (! $assignable) {
                return;
            }

            $this->reuseLoadedAccount($locked, $transaction);

            foreach ($lookups->periodsCovering($locked) as $period) {
                $amount = $this->amountForBudget($locked, $period->budget, $lookups);
                if ($amount === 0) {
                    continue;
                }

                $matchingPeriodIds[] = $period->id;
                $assignment = BudgetTransaction::updateOrCreate(
                    ['transaction_id' => $transaction->id, 'budget_period_id' => $period->id],
                    ['amount' => $amount],
                );

                if ($assignment->wasRecentlyCreated) {
                    $createdPeriodIds[] = $period->id;
                }
            }

            BudgetTransaction::query()
                ->where('transaction_id', $transaction->id)
                ->whereHas('budgetPeriod.budget', fn ($query) => $query->whereNull('archived_at'))
                ->when($matchingPeriodIds !== [], fn ($query) => $query->whereNotIn('budget_period_id', $matchingPeriodIds))
                ->delete();
        }, attempts: 5);

        if ($notify && $assignable) {
            $this->notifications->handleAssignment($transaction, $matchingPeriodIds, $createdPeriodIds);
        }
    }

    /**
     * Rewrite the budget snapshots of an account whose ownership share changed.
     *
     * The amounts were written when each transaction was assigned, so a new
     * share only reaches the budgets already counting this account if something
     * recomputes them. Recomputed rather than rescaled in SQL: a split
     * transaction contributes only the postings a budget tracks, so its
     * snapshot is not a fixed fraction of the transaction's amount.
     *
     * @return int the number of transactions queued for a fresh assignment
     */
    public function reweighAccountSnapshots(Account $account): int
    {
        $transactionIds = BudgetTransaction::query()
            ->join('transactions', 'transactions.id', '=', 'budget_transactions.transaction_id')
            ->where('transactions.account_id', $account->id)
            ->distinct()
            ->pluck('transactions.id')
            ->all();

        if ($transactionIds === []) {
            return 0;
        }

        // Every affected period now holds a different total, so the limit alerts
        // it already sent describe a state that no longer exists. Clearing the
        // flags lets the next crossing notify again, the same way a refund that
        // drops a budget back under its limit does.
        BudgetPeriod::query()
            ->whereHas(
                'budgetTransactions.transaction',
                fn (Builder $query) => $query->where('account_id', $account->id),
            )
            ->update(['close_to_limit_notified' => false, 'over_limit_notified' => false]);

        ReassignTransactionsToBudgets::dispatch($transactionIds, notify: false);

        return count($transactionIds);
    }

    public function unassignTransaction(Transaction $transaction): void
    {
        BudgetTransaction::query()->where('transaction_id', $transaction->id)->delete();
    }

    public function assignHistoricalTransactionsToPeriod(BudgetPeriod $period): int
    {
        $budget = $this->budgetOfPeriod($period);
        if (! $budget) {
            return 0;
        }

        $lookups = new BudgetAssignmentLookups($this->tree);
        $count = 0;
        $this->transactionsOfPeriod($budget, $period)
            ->chunk(500, function (Collection $transactions) use ($period, $budget, $lookups, &$count): void {
                $count += $this->recordSnapshots($transactions, $period, $budget, $lookups);
            });

        return $count;
    }

    /**
     * Bring a catch-all period back in line with what the other budgets count,
     * silently, like every historical assignment. Runs under the period's row
     * lock, the one every other reconciliation of the period takes.
     *
     * A period still running is re-derived, except that a claim by another
     * budget takes a row away only as far as that budget's rows account for it.
     * Rows can still change there with no budget involved: an expense that is
     * not one any more, or an amount out of date. Those come back in
     * `unproved_changes`. A closed period keeps the figure it ran on: it absorbs
     * nothing and loses only a proved double count. What a claim cannot prove
     * stays as it is and comes back in `left_alone`.
     *
     * @return CatchAllChanges
     */
    public function rebuildCatchAllPeriod(BudgetPeriod $period): array
    {
        return DB::transaction(function () use ($period): array {
            BudgetPeriod::query()->whereKey($period->id)->lockForUpdate()->first();

            return $this->reconcileCatchAllPeriod($period, write: true);
        }, attempts: 5);
    }

    /**
     * What {@see self::rebuildCatchAllPeriod()} would change, without changing it.
     *
     * @return CatchAllChanges
     */
    public function catchAllDrift(BudgetPeriod $period): array
    {
        return $this->reconcileCatchAllPeriod($period, write: false);
    }

    /**
     * @return CatchAllChanges
     */
    private function reconcileCatchAllPeriod(BudgetPeriod $period, bool $write): array
    {
        $totals = [
            'released_double_count' => 0,
            'reduced_double_count' => 0,
            'released_other' => 0,
            'adjusted' => 0,
            'absorbed' => 0,
            'spent_change' => 0,
            'left_alone' => [],
            'unproved_changes' => [],
        ];
        $catchAll = $this->budgetOfPeriod($period);

        if (! $catchAll?->is_catch_all || $catchAll->isArchived()) {
            return $totals;
        }

        $lookups = new BudgetAssignmentLookups($this->tree);
        $frozen = $period->hasEnded();

        $this->transactionsOfPeriod($catchAll, $period)
            ->when($frozen, fn (Builder $query) => $query
                ->whereHas('budgetTransactions', fn (Builder $rows) => $rows->where('budget_period_id', $period->id))
                ->whereHas('budgetTransactions.budgetPeriod.budget', fn (Builder $budgets) => $budgets->where('is_catch_all', false)))
            ->chunkById(500, function (Collection $transactions) use ($period, $catchAll, $lookups, $frozen, $write, &$totals): void {
                $verdicts = $this->catchAllVerdicts($transactions, $period, $catchAll, $lookups, $frozen);
                $changes = $this->snapshotChanges($period, collect($verdicts)->map(fn (array $verdict): int => $verdict['amount']));

                if ($write) {
                    $this->writeSnapshots($period, $changes);
                }

                $totals = $this->addCatchAllChanges($totals, $changes, $verdicts, $transactions);
            });

        return $totals;
    }

    /**
     * @param  CatchAllChanges  $totals
     * @param  SnapshotChanges  $changes
     * @param  array<string, CatchAllVerdict>  $verdicts
     * @param  Collection<int, Transaction>  $transactions
     * @return CatchAllChanges
     */
    private function addCatchAllChanges(array $totals, array $changes, array $verdicts, Collection $transactions): array
    {
        $doubleCounted = array_keys(array_filter($verdicts, fn (array $verdict): bool => $verdict['reason'] === self::DOUBLE_COUNT));
        $reduced = count(array_intersect(array_column($changes['rows'], 'transaction_id'), $doubleCounted));

        $totals['released_double_count'] += count(array_intersect($changes['released_ids'], $doubleCounted));
        $totals['reduced_double_count'] += $reduced;
        $totals['released_other'] += count(array_diff($changes['released_ids'], $doubleCounted));
        $totals['adjusted'] += $changes['adjusted'] - $reduced;
        $totals['absorbed'] += $changes['absorbed'];
        $totals['spent_change'] += $changes['spent_change'];

        foreach ($transactions as $transaction) {
            $verdict = $verdicts[$transaction->id];
            $row = ['transaction_id' => $transaction->id, 'transaction_date' => $transaction->transaction_date->toDateString()];

            if ($verdict['reason'] !== null && $verdict['reason'] !== self::DOUBLE_COUNT) {
                $totals['left_alone'][] = [...$row, 'amount' => $verdict['amount'], 'reason' => $verdict['reason']];
            } elseif ($verdict['reason'] === null && isset($changes['previous_amounts'][$transaction->id])) {
                $totals['unproved_changes'][] = [...$row, 'from' => $changes['previous_amounts'][$transaction->id], 'to' => $verdict['amount']];
            }
        }

        return $totals;
    }

    private function budgetOfPeriod(BudgetPeriod $period): ?Budget
    {
        return $period->budget()->with(['categories:id', 'labels:id'])->first();
    }

    /**
     * The transactions dated inside a period, with all a snapshot reads.
     *
     * @return Builder<Transaction>
     */
    private function transactionsOfPeriod(Budget $budget, BudgetPeriod $period): Builder
    {
        return Transaction::query()
            ->where('user_id', $budget->user_id)
            ->where('space_id', $budget->space_id)
            ->whereBetween('transaction_date', [$period->start_date, $period->end_date])
            ->with(['account' => fn ($query) => $query->withTrashed(), 'labels', 'category', 'splits.category']);
    }

    /**
     * Write one period's snapshots for a chunk of transactions in one lookup,
     * one upsert and one delete, rather than all three per transaction.
     *
     * Behaves as updateOrCreate() would row by row: a missing snapshot is
     * inserted, one whose amount changed is refreshed, and one already holding
     * the right amount is left untouched, updated_at included. A transaction
     * the budget no longer counts loses its snapshot. Eloquent's upsert() fills
     * in the ids and timestamps the model would have set.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return int the number of snapshots newly created
     */
    private function recordSnapshots(Collection $transactions, BudgetPeriod $period, Budget $budget, BudgetAssignmentLookups $lookups): int
    {
        $wantedAmounts = $budget->is_catch_all
            ? collect($this->catchAllVerdicts($transactions, $period, $budget, $lookups, frozen: false))
                ->map(fn (array $verdict): int => $verdict['amount'])
            : $transactions->mapWithKeys(fn (Transaction $transaction): array => [
                $transaction->id => $this->amountForBudget($transaction, $budget, $lookups),
            ]);

        $changes = $this->snapshotChanges($period, $wantedAmounts);
        $this->writeSnapshots($period, $changes);

        return $changes['absorbed'];
    }

    /**
     * What a catch-all period should hold of each transaction, and why when that
     * is not simply what it holds or would absorb. `$frozen` is a closed period
     * being healed, as opposed to a period running or backfilled for the first
     * time, which is written whole.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return array<string, CatchAllVerdict> keyed by transaction id
     */
    private function catchAllVerdicts(Collection $transactions, BudgetPeriod $period, Budget $catchAll, BudgetAssignmentLookups $lookups, bool $frozen): array
    {
        $held = $lookups->rowsHeldElsewhere($catchAll, $transactions->modelKeys());
        $existingAmounts = $period->budgetTransactions()
            ->whereIn('transaction_id', $transactions->modelKeys())
            ->pluck('amount', 'transaction_id');
        $verdicts = [];

        foreach ($transactions as $transaction) {
            $existing = $existingAmounts->get($transaction->id);
            ['holders' => $holders, 'doubt' => $doubt] = $this->readHolders($transaction, $held[$transaction->id] ?? [], $lookups, $frozen);

            if ($doubt) {
                $verdicts[$transaction->id] = ['amount' => (int) $existing, 'reason' => $existing === null ? self::NOT_ABSORBED_UNEXPLAINED : self::HOLDER_ROW_UNEXPLAINED];

                continue;
            }

            $proved = $this->amountOutside($transaction, $catchAll, $holders, $holders, $lookups);

            $verdicts[$transaction->id] = $frozen
                ? $this->frozenVerdict((int) $existing, $proved, $holders)
                : $this->runningVerdict($transaction, $catchAll, $existing, $proved, $holders, $lookups);
        }

        return $verdicts;
    }

    /**
     * Which budgets a transaction's rows prove are counting it, and whether a
     * row stands unexplained.
     *
     * A row explains itself when it is what its budget would write today, which
     * also tells which split lines it covers. Failing that, a row that can no
     * longer change (a closed period, an archived budget) still proves a budget
     * counts all of an unsplit transaction when it holds the whole of it. Any
     * other frozen row leaves the transaction in doubt. A row in a running period
     * proves nothing by itself: that period is re-derived from what its budget
     * tracks, so a pending tracking change may be about to drop it. Only a
     * frozen catch-all period, which will not be looked at again, treats that as
     * doubt rather than ignoring it.
     *
     * @param  iterable<int, BudgetTransaction>  $rows
     * @return array{holders: SupportCollection<int, Budget>, doubt: bool}
     */
    private function readHolders(Transaction $transaction, iterable $rows, BudgetAssignmentLookups $lookups, bool $frozen): array
    {
        $holders = collect();
        $doubt = false;

        foreach ($rows as $row) {
            $budget = $row->budgetPeriod->budget;
            $rowIsFrozen = $row->budgetPeriod->hasEnded() || $budget->isArchived();
            $wholeTransaction = $transaction->splits->isEmpty() && $row->amount === -$this->ownedAmount($transaction, $transaction->amount);

            if ($row->amount === $this->amountForBudget($transaction, $budget, $lookups) || ($rowIsFrozen && $wholeTransaction)) {
                $holders->push($budget);
            } elseif ($rowIsFrozen || $frozen) {
                $doubt = true;
            }
        }

        return ['holders' => $holders, 'doubt' => $doubt];
    }

    /**
     * A closed period keeps its figure unless the rows prove part of it is
     * counted twice. Never grows.
     *
     * @param  SupportCollection<int, Budget>  $holders
     * @return CatchAllVerdict
     */
    private function frozenVerdict(int $existing, int $proved, SupportCollection $holders): array
    {
        return $holders->isNotEmpty() && abs($proved) < abs($existing)
            ? ['amount' => $proved, 'reason' => self::DOUBLE_COUNT]
            : ['amount' => $existing, 'reason' => null];
    }

    /**
     * A period written whole: it takes what nothing claims, and a claim takes a
     * row away only as far as other rows account for it. A claim by tracking
     * alone keeps an expense from being absorbed, and never moves a row: if the
     * budget has not got it (a backfill that failed, a period generated later),
     * the catch-all may be the only thing counting it. Told apart by the amounts
     * themselves rather than by size, since a split with a refund line can come
     * out larger once a line is claimed.
     *
     * @param  SupportCollection<int, Budget>  $holders
     * @return CatchAllVerdict
     */
    private function runningVerdict(Transaction $transaction, Budget $catchAll, ?int $existing, int $proved, SupportCollection $holders, BudgetAssignmentLookups $lookups): array
    {
        $claimants = $holders->merge($lookups->runningClaimants($catchAll, $transaction))->unique('id');
        $wanted = $this->amountOutside($transaction, $catchAll, $claimants, $holders, $lookups);

        if ($existing === null || $wanted === $existing) {
            return ['amount' => $wanted, 'reason' => null];
        }

        if ($wanted !== $proved) {
            return ['amount' => $existing, 'reason' => self::CLAIMED_NOT_HELD];
        }

        return ['amount' => $wanted, 'reason' => $holders->isNotEmpty() && abs($wanted) < abs($existing) ? self::DOUBLE_COUNT : null];
    }

    /**
     * What is left for a catch-all outside $claimants. A budget proved to hold an
     * unsplit transaction holds all of it, whatever it tracks today; anything
     * else is read line by line.
     *
     * @param  SupportCollection<int, Budget>  $claimants
     * @param  SupportCollection<int, Budget>  $holders  those of them proved by a row
     */
    private function amountOutside(Transaction $transaction, Budget $catchAll, SupportCollection $claimants, SupportCollection $holders, BudgetAssignmentLookups $lookups): int
    {
        return $holders->isNotEmpty() && $transaction->splits->isEmpty()
            ? 0
            : $this->unclaimedAmount($transaction, $catchAll, $claimants, $lookups);
    }

    /**
     * How the snapshots of a period differ from the amounts it should hold.
     *
     * @param  SupportCollection<string, int>  $wantedAmounts  keyed by transaction id
     * @return SnapshotChanges
     */
    private function snapshotChanges(BudgetPeriod $period, SupportCollection $wantedAmounts): array
    {
        $existingAmounts = BudgetTransaction::query()
            ->where('budget_period_id', $period->id)
            ->whereIn('transaction_id', $wantedAmounts->keys()->all())
            ->pluck('amount', 'transaction_id');

        $changes = ['rows' => [], 'released_ids' => [], 'previous_amounts' => [], 'absorbed' => 0, 'adjusted' => 0, 'spent_change' => 0];

        foreach ($wantedAmounts as $transactionId => $amount) {
            $existing = $existingAmounts->get($transactionId);

            if ($existing === $amount || ($existing === null && $amount === 0)) {
                continue;
            }

            $changes['spent_change'] += $amount - (int) $existing;

            if ($existing !== null) {
                $changes['previous_amounts'][$transactionId] = $existing;
            }

            if ($amount === 0) {
                $changes['released_ids'][] = $transactionId;

                continue;
            }

            $changes[$existing === null ? 'absorbed' : 'adjusted']++;
            $changes['rows'][] = [
                'transaction_id' => $transactionId,
                'budget_period_id' => $period->id,
                'amount' => $amount,
            ];
        }

        return $changes;
    }

    /**
     * @param  SnapshotChanges  $changes
     */
    private function writeSnapshots(BudgetPeriod $period, array $changes): void
    {
        if ($changes['released_ids'] !== []) {
            BudgetTransaction::query()
                ->where('budget_period_id', $period->id)
                ->whereIn('transaction_id', $changes['released_ids'])
                ->delete();
        }

        BudgetTransaction::upsert($changes['rows'], ['transaction_id', 'budget_period_id'], ['amount']);
    }

    /**
     * What $budget, as it tracks today, takes of $transaction: the whole of
     * it on a tracked label, otherwise the postings in its categories, or for
     * a catch-all what no other budget claims. An expense counts positive, as
     * a snapshot stores it. The budget's categories and labels must be loaded.
     *
     * Public so a budget row can be explained without writing one
     * ({@see SplitBudgetRows}).
     */
    public function amountForBudget(Transaction $transaction, Budget $budget, BudgetAssignmentLookups $lookups): int
    {
        $labelMatch = $budget->labels->pluck('id')->intersect($transaction->labels->pluck('id'))->isNotEmpty();
        if ($labelMatch) {
            return -$this->ownedAmount($transaction, $transaction->amount);
        }

        if ($budget->is_catch_all) {
            $claimants = $lookups->catchAllClaimants($budget, $transaction);

            // An archived claimant holds this transaction, see catchAllClaimants().
            return $this->amountOutside($transaction, $budget, $claimants, $claimants->filter(fn (Budget $other): bool => $other->isArchived()), $lookups);
        }

        $budgetCategoryIds = $lookups->expand(
            $budget->user_id,
            $budget->categories->pluck('id')->all(),
            $budget->space_id,
        );

        return -$this->postings->forTransaction($transaction)
            ->whereIn('categoryId', $budgetCategoryIds)
            ->sum(fn ($posting): int => $this->ownedAmount($transaction, $posting->amount));
    }

    /**
     * What a catch-all takes of a transaction: nothing when a label claims all
     * of it, otherwise the expense postings none of $claimants tracks.
     *
     * @param  SupportCollection<int, Budget>  $claimants
     */
    private function unclaimedAmount(Transaction $transaction, Budget $catchAll, SupportCollection $claimants, BudgetAssignmentLookups $lookups): int
    {
        $transactionLabelIds = $transaction->labels->pluck('id');

        $claimedByLabel = $claimants->contains(
            fn (Budget $other): bool => $other->labels->pluck('id')->intersect($transactionLabelIds)->isNotEmpty(),
        );
        if ($claimedByLabel) {
            return 0;
        }

        $claimed = $lookups->expand(
            $catchAll->user_id,
            $claimants
                ->flatMap(fn (Budget $other): mixed => $other->categories->pluck('id'))
                ->unique()
                ->all(),
            $catchAll->space_id,
        );

        return -$this->postings->forTransaction($transaction)
            ->filter(fn ($posting): bool => $posting->category?->type === CategoryType::Expense && ! in_array($posting->categoryId, $claimed, true))
            ->sum(fn ($posting): int => $this->ownedAmount($transaction, $posting->amount));
    }

    /**
     * Hand the locked copy the account the caller already loaded, so a job that
     * eager loads a chunk's accounts does not pay one lookup per row again. The
     * row lock does not guard the account, so reading it before the lock is no
     * staler than reading it after. A relation loaded as null (a trashed account
     * read without withTrashed()) is not trusted: the copy loads its own.
     */
    private function reuseLoadedAccount(Transaction $locked, Transaction $loaded): void
    {
        $account = $loaded->relationLoaded('account') ? $loaded->account : null;

        if ($account !== null && $account->id === $locked->account_id) {
            $locked->setRelation('account', $account);

            return;
        }

        $locked->load(['account' => fn ($query) => $query->withTrashed()]);
    }

    /**
     * The owner's slice of an amount on the transaction's account. A budget is
     * one person's plan, so a jointly held account only contributes the share
     * that person owns. Loaded with trashed accounts so a deleted one still
     * weighs its transactions instead of dropping them to face value.
     */
    private function ownedAmount(Transaction $transaction, int $amount): int
    {
        $transaction->loadMissing(['account' => fn ($query) => $query->withTrashed()]);

        return Account::shareOf($amount, $transaction->account?->ownership_percentage);
    }
}
