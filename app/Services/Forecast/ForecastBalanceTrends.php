<?php

namespace App\Services\Forecast;

use App\Models\Account;
use App\Models\Transaction;
use App\Services\BalanceLookup;
use App\Services\Forecast\Data\BalanceTrend;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * The balance trend of each account that only has a balance history: no
 * transaction in the QUIET_DAYS before the projection starts (an investment
 * or pension, a savings account updated by hand), but balances on file. Each
 * of the last MONTHS complete months gives its month-end balance, the last
 * one known on or before that day; a month with nothing known yet is left
 * out. An account with recent transactions, or with no balance at all, has
 * no trend: its movements, or nothing, are what it is projected from.
 */
class ForecastBalanceTrends
{
    /**
     * Days without a transaction that make an account balance-only.
     */
    private const QUIET_DAYS = 90;

    /**
     * Complete months the line is fitted over.
     */
    private const MONTHS = 12;

    public function __construct(private readonly BalanceTrendFitter $fitter) {}

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  string  $startDate  `Y-m-d`, the projection's first day.
     * @return array<string, BalanceTrend> By account id; only balance-only accounts.
     */
    public function for(Collection $accounts, string $startDate): array
    {
        $candidates = array_values(array_diff($accounts->modelKeys(), $this->recentlyMoved($accounts->modelKeys(), $startDate)));

        if ($candidates === []) {
            return [];
        }

        $monthEnds = $this->monthEnds($startDate);
        $lookup = BalanceLookup::forAccounts($candidates, $monthEnds[0], Carbon::parse($startDate));
        $trends = [];

        foreach ($candidates as $accountId) {
            $balances = array_filter(
                array_map(fn (Carbon $day): ?int => $lookup->hasHistoryOn($accountId, $day) ? $lookup->getBalanceAt($accountId, $day) : null, $monthEnds),
                fn (?int $balance): bool => $balance !== null,
            );

            if ($balances !== []) {
                $trends[$accountId] = $this->fitter->fit($balances);
            }
        }

        return $trends;
    }

    /**
     * @param  list<string>  $accountIds
     * @return list<string>
     */
    private function recentlyMoved(array $accountIds, string $startDate): array
    {
        return Transaction::query()
            ->withoutEstimates()
            ->whereIn('account_id', $accountIds)
            ->where('transaction_date', '>=', Carbon::parse($startDate)->subDays(self::QUIET_DAYS)->toDateString())
            ->distinct()
            ->pluck('account_id')
            ->all();
    }

    /**
     * The last day of each of the MONTHS complete months before the start
     * month, oldest first.
     *
     * @return list<Carbon>
     */
    private function monthEnds(string $startDate): array
    {
        $startMonth = Carbon::parse($startDate)->startOfMonth();

        return array_map(
            fn (int $index): Carbon => $startMonth->copy()->subMonthsNoOverflow(self::MONTHS - $index)->endOfMonth()->startOfDay(),
            range(0, self::MONTHS - 1),
        );
    }
}
