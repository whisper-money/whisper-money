<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use LogicException;

class BalanceLookup
{
    /**
     * Net movement per account per day, used to carry a balance across the gap
     * between the day it was recorded and the day being asked about.
     *
     * @var array<string, array<string, int>>
     */
    private array $dailyNetByAccount = [];

    /**
     * The walked-back accounts (see `walkBackAccountIds()`) that moved at least
     * once on or before the range end, keyed by account ID.
     *
     * @var array<string, true>
     */
    private array $accountsWithMovements = [];

    /**
     * The first day the lookup was loaded for; no movement before it is read.
     */
    private string $rangeStart = '';

    /**
     * Sorted balance records grouped by account ID.
     * Each account maps to a list of ['date' => string, 'balance' => int, 'invested_amount' => ?int].
     *
     * @var array<string, list<array{date: string, balance: int, invested_amount: ?int}>>
     */
    private array $balancesByAccount = [];

    /**
     * Sorted invested-amount-only records grouped by account ID.
     * Filters out null invested_amount entries for carry-forward lookup.
     *
     * @var array<string, list<array{date: string, invested_amount: int}>>
     */
    private array $investedByAccount = [];

    /**
     * Preload all balance data for a set of accounts covering the given date range.
     *
     * Executes at most 6 queries, none of them reading an account's whole history:
     * 1. A derived-table join to find the latest balance record before the range start per account.
     * 2. A derived-table join to find the latest non-null invested_amount before the range start per account.
     * 3. All balance records within the range.
     * 4. The accounts that only count as a share of their balance.
     * 5. The net movement per account per day inside the range, for the accounts
     *    whose balance may have to be walked back (see `dailyNetFor()`).
     * 6. Whether those that moved nothing inside the range moved anything before
     *    it (see `walkBackAccountsThatMoved()`).
     *
     * Every date asked about must fall on or after the range start: movements
     * before it are never read, so a balance cannot be walked back past it, and
     * `getBalanceAt()` refuses to rather than answer from half the movements.
     *
     * Those accounts get every figure scaled down to the owner's share here,
     * so every reader (net worth, evolution charts, account metrics) stays
     * consistent. Callers that need the real bank balance — the balance editor,
     * imports, bank sync — read `account_balances` directly and are unaffected.
     *
     * @param  Collection<int, string>|array<string>  $accountIds
     */
    public static function forAccounts(Collection|array $accountIds, Carbon $rangeStart, Carbon $rangeEnd): self
    {
        $instance = new self;
        $instance->rangeStart = $rangeStart->toDateString();

        if (empty($accountIds)) {
            return $instance;
        }

        $accountIdList = $accountIds instanceof Collection ? $accountIds->all() : $accountIds;
        $startDate = $rangeStart->toDateString();
        $endDate = $rangeEnd->toDateString();

        // Query 1: Get the latest balance record before the range start for each account.
        // Uses a derived table with GROUP BY + MAX() joined back, avoiding correlated subquery.
        $carryForwardRecords = AccountBalance::query()
            ->whereIn('account_balances.account_id', $accountIdList)
            ->joinSub(
                AccountBalance::query()
                    ->selectRaw('account_id, MAX(balance_date) as max_date')
                    ->whereIn('account_id', $accountIdList)
                    ->where('balance_date', '<', $startDate)
                    ->groupBy('account_id'),
                'latest',
                function ($join) {
                    $join->on('account_balances.account_id', '=', 'latest.account_id')
                        ->on('account_balances.balance_date', '=', 'latest.max_date');
                }
            )
            ->get(['account_balances.account_id', 'account_balances.balance_date', 'account_balances.balance', 'account_balances.invested_amount']);

        // Query 2: Get the latest non-null invested_amount before the range start for each account.
        $investedCarryForwardRecords = AccountBalance::query()
            ->whereIn('account_balances.account_id', $accountIdList)
            ->joinSub(
                AccountBalance::query()
                    ->selectRaw('account_id, MAX(balance_date) as max_date')
                    ->whereIn('account_id', $accountIdList)
                    ->where('balance_date', '<', $startDate)
                    ->whereNotNull('invested_amount')
                    ->groupBy('account_id'),
                'latest_invested',
                function ($join) {
                    $join->on('account_balances.account_id', '=', 'latest_invested.account_id')
                        ->on('account_balances.balance_date', '=', 'latest_invested.max_date');
                }
            )
            ->get(['account_balances.account_id', 'account_balances.balance_date', 'account_balances.invested_amount']);

        // Query 3: All balance records within the range.
        $rangeRecords = AccountBalance::query()
            ->whereIn('account_id', $accountIdList)
            ->whereBetween('balance_date', [$startDate, $endDate])
            ->orderBy('balance_date')
            ->get(['account_id', 'balance_date', 'balance', 'invested_amount']);

        // The accounts that only count towards the user's figures as a share of
        // their balance; everything else is read at face value.
        $sharedAccounts = Account::query()
            ->whereIn('id', $accountIdList)
            ->where('ownership_applies_to_balance', true)
            ->get()
            ->keyBy('id');

        $walkBackAccountIds = self::walkBackAccountIds($accountIdList, $carryForwardRecords, $rangeRecords);
        $dailyNet = self::dailyNetFor($walkBackAccountIds, $startDate, $endDate);
        $instance->accountsWithMovements = self::walkBackAccountsThatMoved($walkBackAccountIds, $dailyNet, $startDate);

        // Build the per-account sorted arrays
        foreach ($accountIdList as $accountId) {
            $entries = [];
            $investedEntries = [];
            $account = $sharedAccounts->get($accountId);
            $share = static fn (?int $amount): ?int => $amount === null || $account === null
                ? $amount
                : $account->shareOfAmount($amount);

            // Add carry-forward seed
            $seed = $carryForwardRecords->firstWhere('account_id', $accountId);
            if ($seed) {
                $entries[] = [
                    'date' => $seed->balance_date->toDateString(),
                    'balance' => $share($seed->balance),
                    'invested_amount' => $share($seed->invested_amount),
                ];
            }

            // Add invested carry-forward seed
            $investedSeed = $investedCarryForwardRecords->firstWhere('account_id', $accountId);
            if ($investedSeed) {
                $investedEntries[] = [
                    'date' => $investedSeed->balance_date->toDateString(),
                    'invested_amount' => $share($investedSeed->invested_amount),
                ];
            }

            // Add range records
            foreach ($rangeRecords->where('account_id', $accountId) as $record) {
                $dateStr = $record->balance_date->toDateString();
                $entries[] = [
                    'date' => $dateStr,
                    'balance' => $share($record->balance),
                    'invested_amount' => $share($record->invested_amount),
                ];

                if ($record->invested_amount !== null) {
                    $investedEntries[] = [
                        'date' => $dateStr,
                        'invested_amount' => $share($record->invested_amount),
                    ];
                }
            }

            $instance->balancesByAccount[$accountId] = $entries;
            $instance->investedByAccount[$accountId] = $investedEntries;
            $instance->dailyNetByAccount[$accountId] = self::movementsByDay(
                $dailyNet->where('account_id', $accountId),
                $share,
            );
        }

        return $instance;
    }

    /**
     * The accounts whose balance may have to be walked back through their
     * movements: no balance before the range, and at least one inside it. One
     * seeded before the range always has a balance on or before any day in it,
     * and one with no balance at all has nothing to walk back from.
     *
     * @param  list<string>  $accountIds
     * @param  EloquentCollection<int, AccountBalance>  $carryForwardRecords
     * @param  EloquentCollection<int, AccountBalance>  $rangeRecords
     * @return list<string>
     */
    private static function walkBackAccountIds(array $accountIds, EloquentCollection $carryForwardRecords, EloquentCollection $rangeRecords): array
    {
        $seeded = $carryForwardRecords->pluck('account_id')->flip();
        $recordedInRange = $rangeRecords->pluck('account_id')->flip();

        return array_values(array_filter(
            $accountIds,
            fn (string $accountId): bool => ! $seeded->has($accountId) && $recordedInRange->has($accountId),
        ));
    }

    /**
     * What moved through each account on each day of the range, so a balance
     * recorded on one date can be carried to another. Grouped in the database
     * rather than read row by row: a year of movements is a few hundred days,
     * and the alternative is every transaction the user owns.
     *
     * Bounded to the range on both ends. A walk back starts at a balance inside
     * the range and stops at a day on or after its start, so neither the
     * account's older history nor anything past the range end is ever summed.
     *
     * The sum lands in `amount`, the column it is a sum of, so each row is
     * still a transaction-shaped thing with its casts intact rather than a raw
     * one carrying an attribute the model has never heard of.
     *
     * @param  list<string>  $accountIds
     * @return EloquentCollection<int, Transaction>
     */
    private static function dailyNetFor(array $accountIds, string $startDate, string $endDate): EloquentCollection
    {
        if ($accountIds === []) {
            return new EloquentCollection;
        }

        return Transaction::query()
            ->whereIn('account_id', $accountIds)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->selectRaw('account_id, transaction_date, SUM(amount) as amount')
            ->groupBy('account_id', 'transaction_date')
            ->get();
    }

    /**
     * The walked-back accounts with any movement on or before the range end. One
     * that moved inside the range is already known from its grouped rows; one
     * that did not is asked whether it moved before the range. That is an
     * EXISTS correlated on the account, which stops at the first row it finds —
     * going through `Account` is what makes it one, where a DISTINCT over
     * `transactions` would read every row the account has.
     *
     * @param  list<string>  $accountIds
     * @param  EloquentCollection<int, Transaction>  $dailyNet
     * @return array<string, true>
     */
    private static function walkBackAccountsThatMoved(array $accountIds, EloquentCollection $dailyNet, string $startDate): array
    {
        $movedInRange = $dailyNet->pluck('account_id')->unique()->all();
        $quietInRange = array_values(array_diff($accountIds, $movedInRange));

        $movedBeforeRange = $quietInRange === [] ? [] : Account::withTrashed()
            ->whereIn('id', $quietInRange)
            ->whereHas('transactions', fn ($query) => $query->where('transaction_date', '<', $startDate))
            ->pluck('id')
            ->all();

        return array_fill_keys([...$movedInRange, ...$movedBeforeRange], true);
    }

    /**
     * One account's grouped movement rows as a date-keyed, date-sorted map,
     * scaled to the owner's share the same way its balances are.
     *
     * @param  EloquentCollection<int, Transaction>  $rows
     * @param  callable(?int): ?int  $share
     * @return array<string, int>
     */
    private static function movementsByDay(EloquentCollection $rows, callable $share): array
    {
        $movements = [];

        foreach ($rows as $row) {
            $movements[$row->transaction_date->toDateString()] = (int) $share($row->amount);
        }

        ksort($movements);

        return $movements;
    }

    /**
     * The balance of an account on a given date.
     *
     * A balance is a photograph of one day, and banks hand over exactly one of
     * them — today's. Carrying that one figure sideways across a year was what
     * a freshly connected account's net worth chart was made of: twelve
     * identical months and "+0.0% over the last 12 months" drawn over a year
     * of movements nobody had looked at.
     *
     * So the nearest photograph is walked to the day asked about through the
     * movements in between — forwards when it was taken before that day,
     * backwards when it was taken after. Where balances are recorded daily the
     * nearest one is that same day and nothing is walked at all, which is why
     * this does not disturb an account that has been syncing for months.
     *
     * Only ever backwards, never forwards. A balance the bank sent is its own
     * last word on the account, and adding later movements to an older one
     * double-counts everything the bank has already settled — a sandbox that
     * dates its balance to 2019 and its movements to this year turns a €3,333
     * account into minus €121,000 that way. Past the newest balance on file the
     * old carry-forward still stands, because there is nothing better to say.
     *
     * Returns 0 where there is genuinely nothing to say: no balance recorded,
     * or one that only exists on the far side of a stretch with no movements to
     * carry it across. Inventing a flat line there is the bug this method
     * exists to avoid, not a nicer-looking version of it.
     */
    public function getBalanceAt(string $accountId, Carbon $date): int
    {
        $dateStr = $date->toDateString();
        $entries = $this->balancesByAccount[$accountId] ?? [];

        $earlier = null;
        $later = null;

        foreach ($entries as $entry) {
            if ($entry['date'] <= $dateStr) {
                $earlier = $entry;
            } else {
                $later = $entry;
                break;
            }
        }

        // A balance already recorded on or before that day is the answer, the
        // way it always was. Anything after it the bank has since settled into
        // its own later figure, so nothing is added on top.
        if ($earlier !== null) {
            return $earlier['balance'];
        }

        if ($later === null) {
            return 0;
        }

        if ($dateStr < $this->rangeStart) {
            throw new LogicException("Balances were loaded from {$this->rangeStart}, so none can be walked back to {$dateStr}.");
        }

        // Nothing recorded yet on that day, but the account was photographed
        // afterwards — the shape of every account on the day it is connected —
        // so the movements since are what say where it stood. An account with
        // no movements at all is the one case with nothing to work from: there
        // the later balance would simply be repeated across months nobody has
        // any record of, which is the flat line this is here to stop drawing.
        if (! isset($this->accountsWithMovements[$accountId])) {
            return 0;
        }

        return $later['balance'] - $this->netAfter($accountId, $dateStr, $later['date']);
    }

    /**
     * What the account's movements added to its balance between two dates, so
     * that `balance(to) - netAfter(from, to) === balance(from)`.
     *
     * The earlier bound is exclusive and the later one inclusive: a balance is
     * recorded at the close of its own day, so that day's movements are already
     * inside it and must not be counted twice.
     */
    private function netAfter(string $accountId, string $from, string $to): int
    {
        $net = 0;

        foreach ($this->dailyNetByAccount[$accountId] ?? [] as $date => $amount) {
            if ($date > $from && $date <= $to) {
                $net += $amount;
            }
        }

        return $net;
    }

    /**
     * Get the invested amount at a given date for an account (carry-forward semantics).
     * Returns null if no invested amount data is available on or before the given date.
     */
    public function getInvestedAmountAt(string $accountId, Carbon $date): ?int
    {
        $dateStr = $date->toDateString();
        $entries = $this->investedByAccount[$accountId] ?? [];

        $result = null;
        foreach ($entries as $entry) {
            if ($entry['date'] <= $dateStr) {
                $result = $entry['invested_amount'];
            } else {
                break;
            }
        }

        return $result;
    }
}
