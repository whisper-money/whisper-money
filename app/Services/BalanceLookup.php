<?php

namespace App\Services;

use App\Models\AccountBalance;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BalanceLookup
{
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
     * Executes exactly 3 efficient queries (no correlated subqueries):
     * 1. A derived-table join to find the latest balance record before the range start per account.
     * 2. A derived-table join to find the latest non-null invested_amount before the range start per account.
     * 3. All balance records within the range.
     *
     * @param  Collection<int, string>|array<string>  $accountIds
     */
    public static function forAccounts(Collection|array $accountIds, Carbon $rangeStart, Carbon $rangeEnd): self
    {
        $instance = new self;

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

        // Query 2: All balance records within the range.
        $rangeRecords = AccountBalance::query()
            ->whereIn('account_id', $accountIdList)
            ->whereBetween('balance_date', [$startDate, $endDate])
            ->orderBy('balance_date')
            ->get(['account_id', 'balance_date', 'balance', 'invested_amount']);

        // Build the per-account sorted arrays
        foreach ($accountIdList as $accountId) {
            $entries = [];
            $investedEntries = [];

            // Add carry-forward seed
            $seed = $carryForwardRecords->firstWhere('account_id', $accountId);
            if ($seed) {
                $entries[] = [
                    'date' => $seed->balance_date->toDateString(),
                    'balance' => $seed->balance,
                    'invested_amount' => $seed->invested_amount,
                ];
            }

            // Add invested carry-forward seed
            $investedSeed = $investedCarryForwardRecords->firstWhere('account_id', $accountId);
            if ($investedSeed) {
                $investedEntries[] = [
                    'date' => $investedSeed->balance_date->toDateString(),
                    'invested_amount' => $investedSeed->invested_amount,
                ];
            }

            // Add range records
            foreach ($rangeRecords->where('account_id', $accountId) as $record) {
                $dateStr = $record->balance_date->toDateString();
                $entries[] = [
                    'date' => $dateStr,
                    'balance' => $record->balance,
                    'invested_amount' => $record->invested_amount,
                ];

                if ($record->invested_amount !== null) {
                    $investedEntries[] = [
                        'date' => $dateStr,
                        'invested_amount' => $record->invested_amount,
                    ];
                }
            }

            $instance->balancesByAccount[$accountId] = $entries;
            $instance->investedByAccount[$accountId] = $investedEntries;
        }

        return $instance;
    }

    /**
     * Get the balance at a given date for an account (carry-forward semantics).
     * Returns the most recent balance on or before the given date, or 0 if none exists.
     */
    public function getBalanceAt(string $accountId, Carbon $date): int
    {
        $dateStr = $date->toDateString();
        $entries = $this->balancesByAccount[$accountId] ?? [];

        $result = 0;
        foreach ($entries as $entry) {
            if ($entry['date'] <= $dateStr) {
                $result = $entry['balance'];
            } else {
                break;
            }
        }

        return $result;
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
