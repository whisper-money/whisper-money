<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Transaction;
use App\Support\SortedDates;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use LogicException;

class BalanceLookup
{
    /**
     * The days each walked-back account moved on, sorted, keyed by account ID.
     * Read with `SortedDates`, next to the running totals below.
     *
     * @var array<string, list<string>>
     */
    private array $movementDatesByAccount = [];

    /**
     * Each account's net movement summed from the range start through each of
     * its movement days, so the movement between any two dates is one
     * subtraction rather than a walk over every day in between.
     *
     * @var array<string, list<int>>
     */
    private array $runningNetByAccount = [];

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
     * Each account's balance record dates, sorted, with the balances recorded
     * on them at the same positions in `$balancesByAccount`.
     *
     * @var array<string, list<string>>
     */
    private array $balanceDatesByAccount = [];

    /**
     * @var array<string, list<int>>
     */
    private array $balancesByAccount = [];

    /**
     * Each account's dates with a non-null invested amount, sorted, with the
     * amounts at the same positions in `$investedByAccount`. The null ones are
     * left out so the newest known amount carries forward over them.
     *
     * @var array<string, list<string>>
     */
    private array $investedDatesByAccount = [];

    /**
     * @var array<string, list<int>>
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
        $carryForwardRecords = self::rowsByAccount(AccountBalance::query()
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
            ->toBase()
            ->get(['account_balances.account_id', 'account_balances.balance_date', 'account_balances.balance', 'account_balances.invested_amount']));

        // Query 2: Get the latest non-null invested_amount before the range start for each account.
        $investedCarryForwardRecords = self::rowsByAccount(AccountBalance::query()
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
            ->toBase()
            ->get(['account_balances.account_id', 'account_balances.balance_date', 'account_balances.invested_amount']));

        // Query 3: All balance records within the range.
        $rangeRecords = self::rowsByAccount(AccountBalance::query()
            ->whereIn('account_id', $accountIdList)
            ->whereBetween('balance_date', [$startDate, $endDate])
            ->orderBy('balance_date')
            ->toBase()
            ->get(['account_id', 'balance_date', 'balance', 'invested_amount']));

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

        foreach ($accountIdList as $accountId) {
            $account = $sharedAccounts->get($accountId);
            $share = static fn (?int $amount): ?int => $amount === null || $account === null
                ? $amount
                : $account->shareOfAmount($amount);

            // The carry-forward seeds come first: both are dated before the
            // range, and the range records arrive sorted by date.
            $inRange = $rangeRecords[$accountId] ?? [];
            $seed = $carryForwardRecords[$accountId][0] ?? null;
            $investedSeed = $investedCarryForwardRecords[$accountId][0] ?? null;

            $balanceRows = $seed === null ? $inRange : [$seed, ...$inRange];
            $investedRows = array_values(array_filter(
                $investedSeed === null ? $inRange : [$investedSeed, ...$inRange],
                static fn (object $row): bool => $row->invested_amount !== null,
            ));

            $instance->balanceDatesByAccount[$accountId] = self::datesOf($balanceRows, 'balance_date');
            $instance->balancesByAccount[$accountId] = array_map(static fn (object $row): int => $share((int) $row->balance), $balanceRows);
            $instance->investedDatesByAccount[$accountId] = self::datesOf($investedRows, 'balance_date');
            $instance->investedByAccount[$accountId] = array_map(static fn (object $row): int => $share((int) $row->invested_amount), $investedRows);

            [$instance->movementDatesByAccount[$accountId], $instance->runningNetByAccount[$accountId]] = self::runningNet(
                $dailyNet[$accountId] ?? [],
                $share,
            );
        }

        return $instance;
    }

    /**
     * Plain query rows grouped by account ID, each group in the order the
     * query returned it. Grouped once so building an account never scans the
     * rows of every other one.
     *
     * @param  Collection<int, object>  $rows
     * @return array<string, list<object>>
     */
    private static function rowsByAccount(Collection $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row->account_id][] = $row;
        }

        return $grouped;
    }

    /**
     * The `Y-m-d` day of each row's date column. The columns are DATE, so the
     * first ten characters are the day whatever the driver appends to them,
     * which is what the model's `date` cast used to hand back.
     *
     * @param  list<object>  $rows
     * @return list<string>
     */
    private static function datesOf(array $rows, string $column): array
    {
        return array_map(static fn (object $row): string => substr((string) $row->{$column}, 0, 10), $rows);
    }

    /**
     * The accounts whose balance may have to be walked back through their
     * movements: no balance before the range, and at least one inside it. One
     * seeded before the range always has a balance on or before any day in it,
     * and one with no balance at all has nothing to walk back from.
     *
     * @param  list<string>  $accountIds
     * @param  array<string, list<object>>  $carryForwardRecords
     * @param  array<string, list<object>>  $rangeRecords
     * @return list<string>
     */
    private static function walkBackAccountIds(array $accountIds, array $carryForwardRecords, array $rangeRecords): array
    {
        return array_values(array_filter(
            $accountIds,
            fn (string $accountId): bool => ! isset($carryForwardRecords[$accountId]) && isset($rangeRecords[$accountId]),
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
     * Read as plain rows through the model's query, so its scopes (soft
     * deletes) still apply, and returned per account as a date-keyed map.
     *
     * @param  list<string>  $accountIds
     * @return array<string, array<string, int>>
     */
    private static function dailyNetFor(array $accountIds, string $startDate, string $endDate): array
    {
        if ($accountIds === []) {
            return [];
        }

        $rows = Transaction::query()
            ->whereIn('account_id', $accountIds)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->selectRaw('account_id, transaction_date, SUM(amount) as amount')
            ->groupBy('account_id', 'transaction_date')
            ->toBase()
            ->get();

        $dailyNet = [];

        foreach ($rows as $row) {
            $dailyNet[$row->account_id][substr((string) $row->transaction_date, 0, 10)] = (int) $row->amount;
        }

        return $dailyNet;
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
     * @param  array<string, array<string, int>>  $dailyNet
     * @return array<string, true>
     */
    private static function walkBackAccountsThatMoved(array $accountIds, array $dailyNet, string $startDate): array
    {
        $movedInRange = array_map('strval', array_keys($dailyNet));
        $quietInRange = array_values(array_diff($accountIds, $movedInRange));

        $movedBeforeRange = $quietInRange === [] ? [] : Account::withTrashed()
            ->whereIn('id', $quietInRange)
            ->whereHas('transactions', fn ($query) => $query->where('transaction_date', '<', $startDate))
            ->pluck('id')
            ->all();

        return array_fill_keys([...$movedInRange, ...$movedBeforeRange], true);
    }

    /**
     * One account's daily movements as its sorted days and the running total
     * through each of them, scaled to the owner's share the same way its
     * balances are. Each day is scaled before it is summed, so the total
     * between two days is exactly the sum of the scaled days in between.
     *
     * @param  array<string, int>  $movements
     * @param  callable(?int): ?int  $share
     * @return array{0: list<string>, 1: list<int>}
     */
    private static function runningNet(array $movements, callable $share): array
    {
        ksort($movements, SORT_STRING);

        $runningNet = [];
        $total = 0;

        foreach ($movements as $amount) {
            $total += (int) $share($amount);
            $runningNet[] = $total;
        }

        return [array_map('strval', array_keys($movements)), $runningNet];
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
        $dates = $this->balanceDatesByAccount[$accountId] ?? [];
        $earlier = SortedDates::lastIndexOnOrBefore($dates, $dateStr);

        // A balance already recorded on or before that day is the answer, the
        // way it always was. Anything after it the bank has since settled into
        // its own later figure, so nothing is added on top.
        if ($earlier >= 0) {
            return $this->balancesByAccount[$accountId][$earlier];
        }

        if ($dates === []) {
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

        return $this->balancesByAccount[$accountId][0] - $this->netAfter($accountId, $dateStr, $dates[0]);
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
        return $this->runningNetOn($accountId, $to) - $this->runningNetOn($accountId, $from);
    }

    /**
     * The account's movements summed from the range start through that date.
     */
    private function runningNetOn(string $accountId, string $date): int
    {
        $index = SortedDates::lastIndexOnOrBefore($this->movementDatesByAccount[$accountId] ?? [], $date);

        return $index < 0 ? 0 : $this->runningNetByAccount[$accountId][$index];
    }

    /**
     * Get the invested amount at a given date for an account (carry-forward semantics).
     * Returns null if no invested amount data is available on or before the given date.
     */
    public function getInvestedAmountAt(string $accountId, Carbon $date): ?int
    {
        $index = SortedDates::lastIndexOnOrBefore($this->investedDatesByAccount[$accountId] ?? [], $date->toDateString());

        return $index < 0 ? null : $this->investedByAccount[$accountId][$index];
    }
}
