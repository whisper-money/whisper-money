<?php

namespace App\Services;

use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Concerns\ConvertsTransactionCurrency;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @phpstan-type Claim array{categories: array<int, string>, labels: array<int, string>, until: ?string}
 * @phpstan-type Assignment array{transaction: Transaction, periodIds: array<int, string>, amount: int}
 * @phpstan-type SnapshotRow array{transaction_id: string, budget_period_id: string, amount: int}
 */
class BudgetTransactionService
{
    use ConvertsTransactionCurrency;

    /**
     * How many rows a backfill or {@see self::resnapshot()} reads per batch.
     */
    private const CHUNK_SIZE = 500;

    /**
     * Whether a transaction is booked in its owner's currency, by the same
     * fallback as {@see ConvertsTransactionCurrency::sourceCurrency()}: the
     * transaction's currency, else its account's. Only valid on queries that
     * join `transactions`, `accounts` and `users`.
     */
    private const BOOKED_IN_USER_CURRENCY_SQL = "lower(coalesce(nullif(transactions.currency_code, ''), nullif(accounts.currency_code, ''), users.currency_code)) = lower(users.currency_code)";

    public function __construct(
        private readonly ExchangeRateService $exchangeRateService,
        private readonly CategoryTree $tree = new CategoryTree,
        private readonly BudgetNotificationService $notifications = new BudgetNotificationService,
    ) {}

    /**
     * Claims already read for a catch-all period, keyed by period id. Only set
     * while {@see self::assignTransactions()} runs, so a batch reads each
     * period's claims once and the next batch sees any budget created since.
     *
     * @var array<string, array<int, Claim>>|null
     */
    private ?array $claimsByPeriod = null;

    /**
     * Budget periods that may take in a transaction of the batch, keyed by
     * user id. Only set while {@see self::assignTransactions()} runs, so each
     * user's periods are read once per batch instead of twice per transaction.
     *
     * @var array<string, Collection<int, BudgetPeriod>>|null
     */
    private ?array $candidatePeriodsByUser = null;

    /**
     * @param  bool  $notify  set to false for bulk backfills, where the emails
     *                        would describe budget states the user never crossed
     */
    public function assignTransaction(Transaction $transaction, bool $notify = true): void
    {
        $assignment = $this->planAssignment($transaction, categoryParentMap: null);

        $this->applyAssignments($assignment ? [$assignment] : [], $notify);
    }

    /**
     * {@see self::assignTransaction()} for a batch, reading each user's
     * category tree once for the whole batch instead of walking it level by
     * level for every transaction.
     *
     * The tree and the catch-all claims are only held for this call, so the
     * next batch sees any category re-parented or budget created in the meantime.
     * Each user is read once too, for the currency their snapshots are in, and
     * the categories in one query, for {@see self::catchAllPeriodIds()}.
     *
     * Every transaction's periods are worked out first, then written in one
     * database transaction for the whole batch, see {@see self::applyAssignments()}.
     *
     * @param  iterable<int, Transaction>  $transactions
     */
    public function assignTransactions(iterable $transactions, bool $notify = true): void
    {
        $parentMaps = [];
        $users = [];
        $assignments = [];
        $transactions = collect($transactions);

        // Wrapped in an Eloquent collection only for loadMissing(). The relation
        // lands on the same models, with the default soft-delete scope that the
        // per-transaction loadMissing() there uses, so a trashed category still
        // reads as null.
        Collection::make($transactions->all())->loadMissing('category');

        try {
            $this->claimsByPeriod = [];
            $this->candidatePeriodsByUser = $this->loadCandidatePeriods($transactions);

            foreach ($transactions as $transaction) {
                $userId = $transaction->user_id;

                if ($userId && ! $transaction->relationLoaded('user')) {
                    $transaction->setRelation('user', $users[$userId] ??= User::query()->find($userId));
                }

                $parentMap = $userId && $transaction->category_id
                    ? ($parentMaps[$userId] ??= $this->tree->parentMap($userId))
                    : null;

                $assignment = $this->planAssignment($transaction, $parentMap);

                if ($assignment) {
                    // Keyed by id: a transaction listed twice is written once.
                    $assignments[$transaction->id] = $assignment;
                }
            }
        } finally {
            $this->claimsByPeriod = null;
            $this->candidatePeriodsByUser = null;
        }

        $this->applyAssignments(array_values($assignments), $notify);
    }

    /**
     * Every period of each user's budgets overlapping the dates of that user's
     * transactions in the batch, for {@see self::candidatePeriods()} to narrow
     * down per transaction in memory.
     *
     * @param  SupportCollection<int, Transaction>  $transactions
     * @return array<string, Collection<int, BudgetPeriod>>
     */
    private function loadCandidatePeriods(SupportCollection $transactions): array
    {
        return $transactions
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->map(function (SupportCollection $userTransactions, string $userId): Collection {
                $dates = $userTransactions->pluck('transaction_date');

                return $this->periodsOverlapping($userId, $dates->min(), $dates->max());
            })
            ->all();
    }

    /**
     * Periods of the user's budgets overlapping [$from, $to] whose budget was
     * not archived on or before $from, with `budget.categories` and
     * `budget.labels` loaded. An archived budget takes in nothing dated from
     * its archive day on; {@see self::candidatePeriods()} applies that per day.
     *
     * @return Collection<int, BudgetPeriod>
     */
    private function periodsOverlapping(string $userId, Carbon $from, Carbon $to): Collection
    {
        return BudgetPeriod::query()
            // Spelled out rather than via Budget::scopeNotArchived: inside a
            // whereHas closure the builder is not typed to the model.
            ->whereHas('budget', fn ($query) => $query
                ->where('user_id', $userId)
                ->where(fn ($archive) => $archive
                    ->whereNull('archived_at')
                    ->orWhereDate('archived_at', '>', $from->toDateString())))
            ->overlapping($from, $to)
            ->with('budget.categories:id', 'budget.labels:id')
            ->get();
    }

    /**
     * The periods covering the transaction's date whose budget still counts on
     * that day: what {@see self::trackedPeriodIds()} and
     * {@see self::catchAllPeriodIds()} pick from. Read from the batch's preload
     * when there is one, otherwise queried for this day alone.
     *
     * An archived budget stops counting on its archive day, while what is
     * dated before is still its own, as {@see self::claimsOverPeriod()} tells
     * the catch-all — otherwise a late or edited transaction would land in
     * neither. Dates are compared as plain `Y-m-d` strings, as the SQL does
     * (`date(archived_at)`, in UTC like the app).
     *
     * @return Collection<int, BudgetPeriod>
     */
    private function candidatePeriods(Transaction $transaction): Collection
    {
        $date = $transaction->transaction_date;
        $day = $date->toDateString();
        $periods = $this->candidatePeriodsByUser[$transaction->user_id]
            ?? $this->periodsOverlapping($transaction->user_id, $date, $date);

        return $periods->filter(fn (BudgetPeriod $period): bool => $period->start_date->toDateString() <= $day
            && $period->end_date->toDateString() >= $day
            && ($period->budget->archived_at === null || $period->budget->archived_at->toDateString() > $day))
            ->values();
    }

    /**
     * The budget periods the transaction belongs in and the amount its
     * snapshots hold, without writing anything: {@see self::applyAssignments()}
     * does that. Null for a transaction with no owner, which is left alone.
     *
     * @param  array<string, ?string>|null  $categoryParentMap  the user's {@see CategoryTree::parentMap()},
     *                                                          or null to load it for this transaction
     * @return Assignment|null
     */
    private function planAssignment(Transaction $transaction, ?array $categoryParentMap): ?array
    {
        $userId = $transaction->user_id;

        if (! $userId) {
            return null;
        }

        // Ensure labels are available for matching, and the account for the
        // ownership share (both safe if already loaded). The account is loaded
        // with trashed ones too, so this agrees with the SQL re-weigh, which
        // ignores the soft-delete scope as well.
        $transaction->loadMissing('labels');
        $transaction->loadMissing(['account' => fn ($query) => $query->withTrashed()]);
        // The owner, whose currency every snapshot is converted to. Loaded
        // whole: the notifications that follow email this same model.
        $transaction->loadMissing('user');

        // A budget tracking a parent category also covers its children, so a
        // transaction matches a budget when any of its category's ancestors
        // (or itself) is attached to that budget.
        $categoryMatchIds = $transaction->category_id
            ? $this->tree->ancestorAndSelfIds($userId, $transaction->category_id, $categoryParentMap)
            : [];

        $candidatePeriods = $this->candidatePeriods($transaction);
        $matchingPeriodIds = $this->trackedPeriodIds($transaction, $candidatePeriods, $categoryMatchIds);

        // A catch-all budget only absorbs what nothing else counts. Any budget
        // already tracking this transaction — by category or by label — in a
        // period covering its date takes precedence, so the catch-all steps in
        // only when there is no such period.
        if ($matchingPeriodIds === []) {
            $matchingPeriodIds = $this->catchAllPeriodIds($transaction, $candidatePeriods, $categoryMatchIds);
        }

        // Converted here, before applyAssignments() takes its locks: a missing
        // rate may be fetched from the provider, which is no work to hold a
        // row lock over.
        $amount = $matchingPeriodIds === [] ? 0 : $this->snapshotAmount($transaction, $transaction->user->currency_code);

        return ['transaction' => $transaction, 'periodIds' => $matchingPeriodIds, 'amount' => $amount];
    }

    /**
     * Write planned assignments: each transaction ends up with a snapshot in
     * exactly its periods, and in no other.
     *
     * Applied atomically so concurrent workers cannot leave a transaction
     * half-assigned, and the unique index guards duplicates. A batch goes in
     * one database transaction that locks its rows in one query and writes in
     * a handful, rather than all of that once per transaction.
     *
     * The caller bounds the batch (the reassign job chunks 200 rows), which
     * bounds how many rows stay locked, and for how long. A failure rolls the
     * whole batch back, for the job to retry from scratch.
     *
     * Notifications go out after the commit, each with the periods its
     * transaction was newly added to, so each reads the budget's spending
     * with the whole batch in it. One that fails is reported and the rest
     * still go out: the rows are committed, so a retry would see nothing new
     * to announce.
     *
     * @param  array<int, Assignment>  $assignments
     */
    private function applyAssignments(array $assignments, bool $notify): void
    {
        if ($assignments === []) {
            return;
        }

        $createdPeriodIds = [];

        DB::transaction(function () use ($assignments, &$createdPeriodIds): void {
            // Assigned, not appended: a deadlock retry re-runs this closure.
            $createdPeriodIds = $this->writeAssignments($assignments);
        }, attempts: 5);

        if (! $notify) {
            return;
        }

        foreach ($assignments as $assignment) {
            $transaction = $assignment['transaction'];

            try {
                $this->notifications->handleAssignment($transaction, $assignment['periodIds'], $createdPeriodIds[$transaction->id] ?? []);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * @param  array<int, Assignment>  $assignments
     * @return array<string, array<int, string>> the periods each transaction was newly added to, keyed by transaction id
     */
    private function writeAssignments(array $assignments): array
    {
        // Locked in id order, so two batches sharing rows queue up behind each
        // other instead of deadlocking.
        Transaction::query()
            ->whereKey(array_map(fn (array $assignment): string => $assignment['transaction']->id, $assignments))
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        $this->deleteStaleSnapshots($assignments);

        $rows = [];

        foreach ($assignments as $assignment) {
            foreach ($assignment['periodIds'] as $periodId) {
                $rows[] = [
                    'transaction_id' => $assignment['transaction']->id,
                    'budget_period_id' => $periodId,
                    'amount' => $assignment['amount'],
                ];
            }
        }

        return $this->upsertSnapshots($rows);
    }

    /**
     * Drop each transaction's snapshots in periods it no longer belongs in,
     * with one delete per distinct set of periods: transactions matching the
     * same periods (or none, the common case) share a query.
     *
     * @param  array<int, Assignment>  $assignments
     */
    private function deleteStaleSnapshots(array $assignments): void
    {
        collect($assignments)
            ->groupBy(fn (array $assignment): string => collect($assignment['periodIds'])->sort()->implode(','))
            ->each(function (SupportCollection $group): void {
                $periodIds = $group->first()['periodIds'];

                BudgetTransaction::query()
                    ->whereIn('transaction_id', $group->map(fn (array $assignment): string => $assignment['transaction']->id)->all())
                    ->when($periodIds !== [], fn (Builder $query) => $query->whereNotIn('budget_period_id', $periodIds))
                    ->delete();
            });
    }

    public function unassignTransaction(Transaction $transaction): void
    {
        BudgetTransaction::where('transaction_id', $transaction->id)->delete();
    }

    public function assignHistoricalTransactionsToPeriod(BudgetPeriod $period): int
    {
        // Load the budget with its relationships
        $budget = $period->budget()->with(['categories:id', 'labels:id', 'user:id,currency_code'])->first();

        if (! $budget) {
            return 0;
        }

        $assignedCount = 0;

        Log::info('Building query for historical transactions', [
            'user_id' => $budget->user_id,
            'category_ids' => $budget->categories->pluck('id')->all(),
            'label_ids' => $budget->labels->pluck('id')->all(),
            'start_date' => $period->start_date->toDateString(),
            'end_date' => $period->end_date->toDateString(),
        ]);

        $query = $this->historicalQuery($budget, $period)
            // The owning account weighs every snapshot; eager loaded so a
            // 500-row chunk does not turn into 500 account lookups, and with
            // trashed ones so it agrees with the SQL re-weigh.
            ->with(['account' => fn ($query) => $query->withTrashed()]);

        $totalCount = $query->count();
        Log::info("Found {$totalCount} transactions to process in date range");

        // Process in chunks to prevent memory issues
        $query->chunk(self::CHUNK_SIZE, function (Collection $transactions) use ($period, $budget, &$assignedCount) {
            $assignedCount += $this->recordSnapshots($transactions, $period->id, $budget->user->currency_code);
        });

        return $assignedCount;
    }

    /**
     * Bring a catch-all period back in line with what it should absorb today:
     * release the rows another budget claims by now, then backfill the ones
     * nothing claims any more. Silent, like every historical assignment.
     *
     * @return array{released: int, absorbed: int}
     */
    public function rebuildCatchAllPeriod(BudgetPeriod $period): array
    {
        if (! $period->budget?->is_catch_all) {
            return ['released' => 0, 'absorbed' => 0];
        }

        return [
            'released' => $this->staleCatchAllRows($period->budget, $period)->delete(),
            'absorbed' => $this->assignHistoricalTransactionsToPeriod($period),
        ];
    }

    /**
     * What {@see self::rebuildCatchAllPeriod()} would change, without changing it.
     *
     * @return array{released: int, absorbed: int}
     */
    public function catchAllDrift(BudgetPeriod $period): array
    {
        $budget = $period->budget;

        if (! $budget?->is_catch_all) {
            return ['released' => 0, 'absorbed' => 0];
        }

        return [
            'released' => $this->staleCatchAllRows($budget, $period)->count(),
            'absorbed' => $this->historicalQuery($budget, $period)
                ->whereDoesntHave('budgetTransactions', fn (Builder $query) => $query->where('budget_period_id', $period->id))
                ->count(),
        ];
    }

    /**
     * Rows a catch-all period holds although it should not absorb them any more.
     *
     * @return Builder<BudgetTransaction>
     */
    private function staleCatchAllRows(Budget $catchAll, BudgetPeriod $period): Builder
    {
        return BudgetTransaction::query()
            ->where('budget_period_id', $period->id)
            ->whereNotIn('transaction_id', $this->historicalQuery($catchAll, $period)->select('transactions.id'));
    }

    /**
     * Stop an archived budget counting from its archive day on: the rows it
     * holds for transactions dated that day or later are dropped, so the
     * catch-all can take them without counting them twice. What it counted
     * before stays as it was.
     */
    public function releaseFromArchiveDay(Budget $budget): int
    {
        return $this->rowsFromArchiveDay($budget)?->delete() ?? 0;
    }

    /**
     * How many rows {@see self::releaseFromArchiveDay()} would drop.
     */
    public function countFromArchiveDay(Budget $budget): int
    {
        return $this->rowsFromArchiveDay($budget)?->count() ?? 0;
    }

    /**
     * @return Builder<BudgetTransaction>|null null when the budget is running
     */
    private function rowsFromArchiveDay(Budget $budget): ?Builder
    {
        if ($budget->archived_at === null) {
            return null;
        }

        return BudgetTransaction::query()
            ->whereIn('budget_period_id', $budget->periods()->select('id'))
            ->whereHas('transaction', fn (Builder $query) => $query->where('transaction_date', '>=', $budget->archived_at->toDateString()));
    }

    /**
     * The transactions a budget period takes in when backfilled.
     *
     * @return Builder<Transaction>
     */
    private function historicalQuery(Budget $budget, BudgetPeriod $period): Builder
    {
        $query = Transaction::query()
            ->where('user_id', $budget->user_id)
            ->whereBetween('transaction_date', [$period->start_date, $period->end_date])
            ->withoutTrashed();

        if ($budget->is_catch_all) {
            $this->applyCatchAllFilters($query, $period, $budget->user_id);

            return $query;
        }

        // Tracking a parent category also tracks its children's spending.
        $categoryIds = $this->tree->expand($budget->user_id, $budget->categories->pluck('id')->all());
        $labelIds = $budget->labels->pluck('id')->all();

        return $query->where(fn (Builder $tracked) => $this->whereTracks($tracked, $categoryIds, $labelIds));
    }

    /**
     * @param  Builder<Transaction>  $query
     * @param  array<int, string>  $categoryIds  already expanded to their children
     * @param  array<int, string>  $labelIds
     */
    private function whereTracks(Builder $query, array $categoryIds, array $labelIds): void
    {
        $query->whereIn('category_id', $categoryIds)
            ->orWhereHas('labels', fn (Builder $labelQuery) => $labelQuery->whereIn('labels.id', $labelIds));
    }

    /**
     * Record what transactions contribute to budget periods: each row's
     * {@see self::snapshotAmount()}, worked out by the caller, in one lookup
     * and one upsert rather than both per row.
     *
     * Behaves as updateOrCreate() would row by row: a missing snapshot is
     * inserted, one whose amount changed is refreshed, and one already holding
     * the right amount is left untouched, updated_at included. Eloquent's
     * upsert() fills in the ids and timestamps the model would have set.
     *
     * The amount is a snapshot taken here and never revisited, so a later
     * change to the account's share has to go through
     * {@see self::reweighAccountSnapshots()}.
     *
     * @param  array<int, SnapshotRow>  $rows  at most one per transaction and period
     * @return array<string, array<int, string>> the periods each transaction was newly added to, keyed by transaction id
     */
    private function upsertSnapshots(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $snapshotKey = fn (string $transactionId, string $periodId): string => $transactionId.'|'.$periodId;

        $existingAmounts = BudgetTransaction::query()
            ->whereIn('transaction_id', array_unique(array_column($rows, 'transaction_id')))
            ->whereIn('budget_period_id', array_unique(array_column($rows, 'budget_period_id')))
            ->get(['transaction_id', 'budget_period_id', 'amount'])
            ->mapWithKeys(fn (BudgetTransaction $row): array => [$snapshotKey($row->transaction_id, $row->budget_period_id) => $row->amount]);

        $changedRows = [];
        $createdPeriodIds = [];

        foreach ($rows as $row) {
            $key = $snapshotKey($row['transaction_id'], $row['budget_period_id']);

            if (! $existingAmounts->has($key)) {
                $createdPeriodIds[$row['transaction_id']][] = $row['budget_period_id'];
            } elseif ($existingAmounts->get($key) === $row['amount']) {
                continue;
            }

            $changedRows[] = $row;
        }

        BudgetTransaction::upsert($changedRows, ['transaction_id', 'budget_period_id'], ['amount']);

        return $createdPeriodIds;
    }

    /**
     * {@see self::upsertSnapshots()} for a chunk of transactions in one period.
     *
     * @param  Collection<int, Transaction>  $transactions  with `account` loaded
     * @param  string  $currency  the budget owner's currency
     * @return int the number of snapshots newly created
     */
    private function recordSnapshots(Collection $transactions, string $budgetPeriodId, string $currency): int
    {
        $this->preloadExchangeRates($transactions, $currency);

        $rows = $transactions
            ->map(fn (Transaction $transaction): array => [
                'transaction_id' => $transaction->id,
                'budget_period_id' => $budgetPeriodId,
                'amount' => $this->snapshotAmount($transaction, $currency),
            ])
            ->all();

        // One period, so each newly created transaction holds one entry.
        return count($this->upsertSnapshots($rows));
    }

    /**
     * The owner's share of a transaction in $currency, flipped so an expense
     * counts as positive spending: what every budget snapshot of it holds.
     *
     * Converted exactly as the cashflow converts it, rate of the transaction's
     * day included, so a budget row matches what the cashflow counts for that
     * transaction. A day with no rate keeps the amount unconverted, as the
     * cashflow does, so the two still agree. Same-currency rows need no rate.
     */
    private function snapshotAmount(Transaction $transaction, string $currency): int
    {
        return -$this->convertTransactionAmount($transaction, $currency);
    }

    /**
     * Rewrite the given budget rows from their transactions as they stand now,
     * in their owner's currency, the way {@see self::upsertSnapshots()} would
     * write them today. Rows already holding the right amount are left alone,
     * and rows whose transaction is gone are skipped.
     *
     * @param  Builder<BudgetTransaction>  $budgetTransactions
     * @return int the number of rows whose amount changed
     */
    public function resnapshot(Builder $budgetTransactions): int
    {
        $changed = 0;

        $this->eachStaleSnapshot($budgetTransactions, function (BudgetTransaction $row, int $amount) use (&$changed): void {
            $row->update(['amount' => $amount]);
            $changed++;
        });

        return $changed;
    }

    /**
     * Walk the given budget rows and hand every one whose amount differs from
     * what {@see self::resnapshot()} would write to $callback, along with that
     * amount, without writing anything itself. Rows whose transaction is gone
     * are skipped. The row passed in still holds its stored amount and has
     * `transaction.user` loaded.
     *
     * @param  Builder<BudgetTransaction>  $budgetTransactions
     * @param  Closure(BudgetTransaction, int): void  $callback
     */
    public function eachStaleSnapshot(Builder $budgetTransactions, Closure $callback): void
    {
        $budgetTransactions
            ->with(['transaction' => fn ($query) => $query->withTrashed()->with([
                'account' => fn ($account) => $account->withTrashed(),
                'user:id,currency_code',
            ])])
            ->chunkById(self::CHUNK_SIZE, function (Collection $rows) use ($callback): void {
                $this->eachStaleSnapshotInChunk($rows, $callback);
            });
    }

    /**
     * @param  Collection<int, BudgetTransaction>  $rows  with `transaction.account` and `transaction.user` loaded
     * @param  Closure(BudgetTransaction, int): void  $callback
     */
    private function eachStaleSnapshotInChunk(Collection $rows, Closure $callback): void
    {
        $rows = $rows->filter(fn (BudgetTransaction $row): bool => $row->transaction?->user !== null);

        $rows->groupBy(fn (BudgetTransaction $row): string => $row->transaction->user->currency_code)
            ->each(fn (Collection $group, string $currency) => $this->preloadExchangeRates($group->pluck('transaction'), $currency));

        foreach ($rows as $row) {
            $amount = $this->snapshotAmount($row->transaction, $row->transaction->user->currency_code);

            if ($row->amount !== $amount) {
                $callback($row, $amount);
            }
        }
    }

    /**
     * Every budget row whose transaction is booked in a currency other than its
     * owner's, by the fallback {@see self::BOOKED_IN_USER_CURRENCY_SQL} uses:
     * the only rows a conversion can change. Soft-deleted transactions and
     * accounts count, as {@see self::resnapshot()} reads them too.
     *
     * @param  (Closure(Builder<Transaction>): mixed)|null  $scope  narrows the transactions, joined to `accounts` and `users`
     * @return Builder<BudgetTransaction>
     */
    public function foreignCurrencySnapshots(?Closure $scope = null): Builder
    {
        return BudgetTransaction::query()->whereIn('transaction_id', Transaction::query()
            ->withTrashed()
            ->select('transactions.id')
            ->join('accounts', 'accounts.id', '=', 'transactions.account_id')
            ->join('users', 'users.id', '=', 'transactions.user_id')
            ->whereRaw('not ('.self::BOOKED_IN_USER_CURRENCY_SQL.')')
            ->when($scope !== null, $scope));
    }

    /**
     * Re-snapshot every budget row of an account after its ownership share
     * changed. Rows booked in their owner's currency are rewritten in SQL, so
     * they stay one query no matter how much history the account has, using
     * {@see Transaction::OWNED_AMOUNT_SQL} so the rounding matches what
     * {@see self::upsertSnapshots()} would have written. Rows booked in another
     * currency need a rate per day, so they go through {@see self::resnapshot()}.
     *
     * @return int the same-currency rows the SQL update touched plus the
     *             foreign-currency rows whose amount changed
     */
    public function reweighAccountSnapshots(Account $account): int
    {
        $reweighed = DB::table('budget_transactions')
            ->join('transactions', 'transactions.id', '=', 'budget_transactions.transaction_id')
            ->join('accounts', 'accounts.id', '=', 'transactions.account_id')
            ->join('users', 'users.id', '=', 'transactions.user_id')
            ->where('accounts.id', $account->id)
            ->whereRaw(self::BOOKED_IN_USER_CURRENCY_SQL)
            ->update(['budget_transactions.amount' => DB::raw('-('.Transaction::OWNED_AMOUNT_SQL.')')]);

        $reweighed += $this->resnapshot(
            $this->foreignCurrencySnapshots(fn ($query) => $query->where('accounts.id', $account->id)),
        );

        if ($reweighed > 0) {
            $this->resetLimitAlerts(BudgetPeriod::query()->whereHas(
                'budgetTransactions.transaction',
                fn (Builder $query) => $query->where('account_id', $account->id),
            ));
        }

        return $reweighed;
    }

    /**
     * Clear the limit alerts already sent for periods whose total just changed
     * under them: those alerts describe a state that no longer exists, and
     * clearing the flags lets the next crossing notify again, the same way a
     * refund that drops a budget back under its limit does. Sends nothing.
     *
     * @param  Builder<BudgetPeriod>  $periods
     * @return int the number of periods whose flags were cleared
     */
    public function resetLimitAlerts(Builder $periods): int
    {
        return $this->withLimitAlertsSent($periods)
            ->update(['close_to_limit_notified' => false, 'over_limit_notified' => false]);
    }

    /**
     * Narrow $periods to those that already sent a limit alert, the ones
     * {@see self::resetLimitAlerts()} would clear.
     *
     * @param  Builder<BudgetPeriod>  $periods
     * @return Builder<BudgetPeriod>
     */
    public function withLimitAlertsSent(Builder $periods): Builder
    {
        return $periods->where(fn (Builder $query) => $query
            ->where('close_to_limit_notified', true)
            ->orWhere('over_limit_notified', true));
    }

    /**
     * Narrow a transaction query to what a catch-all budget absorbs: expenses
     * no other budget claims over the catch-all period, see {@see self::claimsOverPeriod()}.
     *
     * @param  Builder<Transaction>  $query
     */
    private function applyCatchAllFilters(Builder $query, BudgetPeriod $period, string $userId): void
    {
        $query->whereNotNull('category_id')
            ->whereHas('category', fn ($q) => $q->where('type', CategoryType::Expense->value));

        [$archived, $running] = collect($this->claimsOverPeriod($userId, $period))
            ->partition(fn (array $claim): bool => $claim['until'] !== null);

        // The running budgets claim in one go, keeping the query as small as it was.
        $this->excludeClaim($query, $userId, [
            'categories' => $running->flatMap(fn (array $claim) => $claim['categories'])->unique()->values()->all(),
            'labels' => $running->flatMap(fn (array $claim) => $claim['labels'])->unique()->values()->all(),
            'until' => null,
        ]);

        foreach ($archived as $claim) {
            $this->excludeClaim($query, $userId, $claim);
        }
    }

    /**
     * @param  Builder<Transaction>  $query
     * @param  Claim  $claim
     */
    private function excludeClaim(Builder $query, string $userId, array $claim): void
    {
        $categoryIds = $this->tree->expand($userId, $claim['categories']);

        if ($categoryIds === [] && $claim['labels'] === []) {
            return;
        }

        // A plain negation is safe here: the catch-all only reads categorized
        // rows, so `category_id in (...)` is never NULL.
        $query->whereNot(fn (Builder $claimed) => $claimed
            ->when($claim['until'] !== null, fn (Builder $q) => $q->where('transaction_date', '<', $claim['until']))
            ->where(fn (Builder $tracked) => $this->whereTracks($tracked, $categoryIds, $claim['labels'])));
    }

    /**
     * Budget periods that track this transaction by category or by label and
     * cover its date.
     *
     * @param  Collection<int, BudgetPeriod>  $candidatePeriods  {@see self::candidatePeriods()}
     * @param  array<int, string>  $categoryMatchIds  the transaction's category and its ancestors
     * @return array<int, string>
     */
    private function trackedPeriodIds(Transaction $transaction, Collection $candidatePeriods, array $categoryMatchIds): array
    {
        $transactionLabelIds = $transaction->labels->pluck('id');

        // Narrow down to periods whose budget actually matches the transaction.
        $matchingPeriodIds = [];

        foreach ($candidatePeriods as $period) {
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
     * Catch-all budget periods that should absorb this expense: those covering
     * its date over which no other budget claims it. Judged per catch-all
     * period, the way {@see self::applyCatchAllFilters()} backfills one, so a
     * transaction lands where the rebuild would have put it.
     *
     * @param  Collection<int, BudgetPeriod>  $candidatePeriods  {@see self::candidatePeriods()}
     * @param  array<int, string>  $categoryMatchIds  the transaction's category and its ancestors
     * @return array<int, string>
     */
    private function catchAllPeriodIds(Transaction $transaction, Collection $candidatePeriods, array $categoryMatchIds): array
    {
        if ($transaction->category_id === null) {
            return [];
        }

        $transaction->loadMissing('category');

        if ($transaction->category?->type !== CategoryType::Expense) {
            return [];
        }

        return $candidatePeriods
            ->filter(fn (BudgetPeriod $period): bool => $period->budget->is_catch_all && $period->budget->archived_at === null)
            ->reject(fn (BudgetPeriod $period): bool => collect($this->claimsOverPeriod($transaction->user_id, $period))
                ->contains(fn (array $claim): bool => $this->claimCoversTransaction($claim, $transaction, $categoryMatchIds)))
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * @param  Claim  $claim
     * @param  array<int, string>  $categoryMatchIds
     */
    private function claimCoversTransaction(array $claim, Transaction $transaction, array $categoryMatchIds): bool
    {
        if ($claim['until'] !== null && $transaction->transaction_date->toDateString() >= $claim['until']) {
            return false;
        }

        return array_intersect($claim['categories'], $categoryMatchIds) !== []
            || array_intersect($claim['labels'], $transaction->labels->pluck('id')->all()) !== [];
    }

    /**
     * What the user's other budgets claim away from the given catch-all period:
     * one claim per budget with a period overlapping it, its categories (not
     * yet expanded to their children) and labels.
     *
     * A budget only claims spending it actually counts, so one whose periods do
     * not reach this far back leaves its categories and labels unclaimed here —
     * otherwise the catch-all would drop those expenses without any budget
     * picking them up.
     *
     * An archived budget stopped counting on the day it was archived, so it
     * claims only what is dated before that day (`until`, exclusive): the
     * catch-all picks up the rest. {@see self::releaseFromArchiveDay()} drops
     * the archived budget's own rows from that day on, so nothing counts twice.
     *
     * @return array<int, Claim>
     */
    private function claimsOverPeriod(string $userId, BudgetPeriod $period): array
    {
        if ($this->claimsByPeriod !== null) {
            return $this->claimsByPeriod[$period->id] ??= $this->readClaimsOverPeriod($userId, $period);
        }

        return $this->readClaimsOverPeriod($userId, $period);
    }

    /**
     * @return array<int, Claim>
     */
    private function readClaimsOverPeriod(string $userId, BudgetPeriod $period): array
    {
        return Budget::query()
            ->where('user_id', $userId)
            ->where('is_catch_all', false)
            ->where(fn (Builder $query) => $query
                ->whereNull('archived_at')
                ->orWhereDate('archived_at', '>', $period->start_date->toDateString()))
            // Date-only comparison for the same reason as in BudgetPeriod::scopeOverlapping().
            ->whereHas('periods', fn (Builder $query) => $query
                ->where('start_date', '<=', $period->end_date->toDateString())
                ->where('end_date', '>=', $period->start_date->toDateString()))
            ->with('categories:id', 'labels:id')
            ->get()
            ->map(fn (Budget $budget): array => [
                'categories' => $budget->categories->pluck('id')->all(),
                'labels' => $budget->labels->pluck('id')->all(),
                'until' => $budget->archived_at?->toDateString(),
            ])
            ->all();
    }
}
