<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Net worth at a point in time, in the user's currency.
 *
 * This used to live inside DashboardAnalyticsController. It moved out because a
 * third caller appeared — the monthly summary email — and a figure the user is
 * told by email has to be the same one their dashboard shows, or they stop
 * believing the rest of the report.
 *
 * The chart the dashboard draws honours two per-user toggles (loans and real
 * estate), so this takes them too: pass the user's preferences and the total
 * matches what they see. Credit cards are excluded whatever the preferences say
 * — they are spending accounts, not wealth, and an account is dropped from the
 * day it was archived, both matching what the chart draws.
 */
class NetWorthCalculator
{
    public function __construct(private ExchangeRateService $exchangeRateService) {}

    /**
     * Account types the user has chosen to keep out of their net worth.
     *
     * @return list<AccountType>
     */
    public function excludedTypesFor(User $user): array
    {
        $setting = $user->setting;

        return array_values(array_filter([
            ($setting->include_loans_in_net_worth_chart ?? true) ? null : AccountType::Loan,
            ($setting->include_real_estate_in_net_worth_chart ?? true) ? null : AccountType::RealEstate,
        ]));
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  list<AccountType>  $excludedTypes
     */
    public function at(
        Collection $accounts,
        BalanceLookup $lookup,
        Carbon $date,
        string $userCurrency,
        array $excludedTypes = [],
    ): int {
        $total = 0;

        foreach ($accounts as $account) {
            if (! $this->counts($account, $excludedTypes, $date)) {
                continue;
            }

            $total += $this->contributionOf($account, $lookup, $date, $userCurrency);
        }

        return $total;
    }

    /**
     * @param  list<AccountType>  $excludedTypes
     */
    private function counts(Account $account, array $excludedTypes, Carbon $date): bool
    {
        return $account->type->countsInNetWorth()
            && ! in_array($account->type, $excludedTypes, true)
            && ! $account->isArchivedOn($date);
    }

    /**
     * Liabilities are stored as positive magnitudes, so they always subtract.
     * Assets keep their real sign, so an overdrawn checking account correctly
     * reduces net worth instead of being flipped positive.
     */
    private function contributionOf(Account $account, BalanceLookup $lookup, Carbon $date, string $userCurrency): int
    {
        $converted = $this->exchangeRateService->convert(
            $account->currency_code,
            $userCurrency,
            $lookup->getBalanceAt($account->id, $date),
            $date->toDateString(),
        );

        return $account->type->reducesNetWorth() ? -abs($converted) : $converted;
    }
}
