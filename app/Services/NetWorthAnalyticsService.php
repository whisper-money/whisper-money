<?php

namespace App\Services;

use App\Models\Account;
use App\Models\User;
use Carbon\Carbon;

/**
 * The net worth figures for one user's own accounts, across every space they
 * have: the total at the end of a period against the previous one, and every
 * account's balance over the period. The dashboard, the `get_net_worth` MCP
 * tool and the public API all read them here.
 */
class NetWorthAnalyticsService
{
    public function __construct(
        private AccountMetricsService $accountMetrics,
        private NetWorthCalculator $netWorthCalculator,
    ) {}

    /**
     * The net worth against the previous period and every account's balance
     * over the period, monthly or, when $daily, daily.
     *
     * @return array{granularity: 'daily'|'monthly', current: array{current: int, previous: int, currency_code: string}, evolution: array{data: list<array<string, mixed>>, accounts: mixed, currency_code: string}}
     */
    public function forRange(User $user, PeriodComparator $period, bool $daily): array
    {
        return [
            'granularity' => $daily ? 'daily' : 'monthly',
            'current' => $this->totals($user, $period),
            'evolution' => $this->evolution($user, $period->from, $period->to, $daily),
        ];
    }

    /**
     * The net worth on the period's last day and on the previous period's, in
     * the user's currency. What counts is NetWorthCalculator's to say.
     *
     * @return array{current: int, previous: int, currency_code: string}
     */
    public function totals(User $user, PeriodComparator $period): array
    {
        $previousPeriod = $period->previous();
        $userCurrency = $user->currency_code;

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->get();

        // Load every account's balance history for the compared range in a
        // fixed number of queries instead of one balance lookup per account.
        $lookup = BalanceLookup::forAccounts(
            $accounts->pluck('id'),
            $previousPeriod->to,
            $period->to,
        );

        return [
            'current' => $this->netWorthCalculator->at($accounts, $lookup, $period->to, $userCurrency),
            'previous' => $this->netWorthCalculator->at($accounts, $lookup, $previousPeriod->to, $userCurrency),
            'currency_code' => $userCurrency,
        ];
    }

    /**
     * What `GET /api/v1/analytics/net-worth` reports: forRange() plus the ids
     * of the accounts that make up the net worth.
     *
     * @return array{granularity: 'daily'|'monthly', current: array{current: int, previous: int, currency_code: string}, evolution: array{data: list<array<string, mixed>>, accounts: mixed, currency_code: string}, counted_account_ids: list<string>}
     */
    public function report(User $user, PeriodComparator $period, bool $daily): array
    {
        return [
            ...$this->forRange($user, $period, $daily),
            'counted_account_ids' => $this->countedAccountIds($user),
        ];
    }

    /**
     * The ids of the accounts that make up the net worth.
     *
     * @return list<string>
     */
    private function countedAccountIds(User $user): array
    {
        return Account::query()
            ->where('user_id', $user->id)
            ->get()
            ->filter(fn (Account $account): bool => $this->netWorthCalculator->counts($account))
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * Every account's balance at the end of each month (or on each day, when
     * $daily) between the two dates, converted to the user's currency. The
     * accounts switched off in net worth are left out unless $includeExcluded.
     *
     * @return array{data: list<array<string, mixed>>, accounts: mixed, currency_code: string}
     */
    public function evolution(User $user, Carbon $start, Carbon $end, bool $daily = false, bool $includeExcluded = false): array
    {
        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->when(! $includeExcluded, function ($query) {
                $query->where(function ($query) {
                    $query->whereNull('include_in_net_worth')
                        ->orWhere('include_in_net_worth', true);
                });
            })
            ->with(['bank:id,name,logo'])
            ->get();

        return $daily
            ? $this->accountMetrics->getNetWorthDailyEvolution($user->currency_code, $accounts, $start, $end)
            : $this->accountMetrics->getNetWorthEvolution($user->currency_code, $accounts, $start, $end, $user->localToday());
    }
}
