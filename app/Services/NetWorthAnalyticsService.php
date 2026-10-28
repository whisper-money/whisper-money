<?php

namespace App\Services;

use App\Models\Account;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * The net worth figures for one user's whole account: the total at the end of
 * a period against the previous one, and every account's balance over the
 * period. The dashboard, the `get_net_worth` MCP tool and the public API all
 * read them here.
 */
class NetWorthAnalyticsService
{
    public function __construct(
        private AccountMetricsService $accountMetrics,
        private NetWorthCalculator $netWorthCalculator,
    ) {}

    /**
     * The net worth on the period's last day and on the previous period's, in
     * the user's currency. The account types the user keeps out of the net
     * worth (loans and real estate, unless included) do not count.
     *
     * @return array{current: int, previous: int, currency_code: string}
     */
    public function compared(User $user, PeriodComparator $period): array
    {
        $previousPeriod = $period->previous();
        $userCurrency = $user->currency_code;
        $excludedTypes = $this->netWorthCalculator->excludedTypesFor($user);

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
            'current' => $this->netWorthCalculator->at($accounts, $lookup, $period->to, $userCurrency, $excludedTypes),
            'previous' => $this->netWorthCalculator->at($accounts, $lookup, $previousPeriod->to, $userCurrency, $excludedTypes),
            'currency_code' => $userCurrency,
        ];
    }

    /**
     * Every account's balance at the end of each month (or day, when $daily)
     * between the two dates, converted to the user's currency.
     *
     * @return array{data: list<array<string, mixed>>, accounts: mixed, currency_code: string}
     */
    public function evolution(User $user, Carbon $start, Carbon $end, bool $daily = false): array
    {
        $accounts = $this->accountsWithBank($user);

        return $daily
            ? $this->accountMetrics->getNetWorthDailyEvolution($user->currency_code, $accounts, $start, $end)
            : $this->accountMetrics->getNetWorthEvolution($user->currency_code, $accounts, $start, $end);
    }

    /**
     * @return Collection<int, Account>
     */
    private function accountsWithBank(User $user): Collection
    {
        return Account::query()
            ->where('user_id', $user->id)
            ->with(['bank:id,name,logo'])
            ->get();
    }
}
