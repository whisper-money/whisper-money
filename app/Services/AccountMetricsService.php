<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Models\Account;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class AccountMetricsService
{
    public function __construct(private ExchangeRateService $exchangeRateService) {}

    /**
     * Compute per-account balance metrics for the accounts index page.
     *
     * Returns current/previous balance, diff, invested amount, and a 12-month sparkline history
     * with month labels formatted as "M" (current year) or "M 'y" (previous years).
     *
     * @param  Collection<int, Account>  $accounts
     * @return array<string, array{currentBalance: int, previousBalance: int, diff: int, investedAmount: int|null, history: list<array{date: string, value: int, investedAmount?: int|null}>}>
     */
    public function getAccountMetrics(string $userCurrency, Collection $accounts): array
    {
        $now = Carbon::now();
        $rangeStart = $now->copy()->subMonths(12)->startOfMonth();

        $accountIds = $accounts->pluck('id');
        $lookup = BalanceLookup::forAccounts($accountIds, $rangeStart, $now->copy());

        $metrics = [];

        foreach ($accounts as $account) {
            $history = [];
            $current = $rangeStart->copy();
            $endMonth = $now->copy()->startOfMonth();

            while ($current->lte($endMonth)) {
                $date = $current->copy()->endOfMonth();
                $originalBalance = $lookup->getBalanceAt($account->id, $date);
                $convertedBalance = $this->convertBalance($originalBalance, $account->currency_code, $userCurrency, $date->toDateString());

                $point = [
                    'date' => $this->formatMonth($date),
                    'value' => $convertedBalance,
                ];

                if ($account->type->supportsInvestedAmount()) {
                    $investedAmount = $lookup->getInvestedAmountAt($account->id, $date);
                    $point['investedAmount'] = $investedAmount !== null
                        ? $this->convertBalance($investedAmount, $account->currency_code, $userCurrency, $date->toDateString())
                        : null;
                }

                $history[] = $point;
                $current->addMonth();
            }

            $currentBalance = end($history)['value'] ?? 0;
            $previousBalance = count($history) > 1 ? $history[count($history) - 2]['value'] : 0;

            $investedAmount = null;
            if ($account->type->supportsInvestedAmount()) {
                $rawInvested = $lookup->getInvestedAmountAt($account->id, $now);
                $investedAmount = $rawInvested !== null
                    ? $this->convertBalance($rawInvested, $account->currency_code, $userCurrency, $now->toDateString())
                    : null;
            }

            $metrics[$account->id] = [
                'currentBalance' => $currentBalance,
                'previousBalance' => $previousBalance,
                'diff' => $currentBalance - $previousBalance,
                'investedAmount' => $investedAmount,
                'history' => $history,
            ];
        }

        return $metrics;
    }

    /**
     * Build monthly net worth evolution data points for all accounts.
     *
     * Each point contains the month, timestamp, and per-account converted balances.
     * Accounts with a different currency than the user also get `_original` entries.
     * Investment accounts get `_invested` entries.
     *
     * @param  Collection<int, Account>  $accounts
     * @return array{data: list<array<string, mixed>>, accounts: mixed, currency_code: string}
     */
    public function getNetWorthEvolution(string $userCurrency, Collection $accounts, Carbon $start, Carbon $end): array
    {
        $accountIds = $accounts->pluck('id');

        $lookupEnd = Carbon::now()->gt($end) ? Carbon::now() : $end->copy();
        $lookup = BalanceLookup::forAccounts($accountIds, $start->copy()->startOfMonth(), $lookupEnd);

        $points = [];
        $current = $start->copy()->startOfMonth();
        $endMonth = $end->copy()->startOfMonth();

        while ($current->lte($endMonth)) {
            $date = $current->copy()->endOfMonth();
            $point = [
                'month' => $date->format('Y-m'),
                'timestamp' => $date->timestamp,
            ];

            foreach ($accounts as $account) {
                $originalBalance = $lookup->getBalanceAt($account->id, $date);
                $convertedBalance = $this->convertBalance(
                    $originalBalance,
                    $account->currency_code,
                    $userCurrency,
                    $date->toDateString(),
                );

                $point[$account->id] = $convertedBalance;

                if ($account->currency_code !== $userCurrency) {
                    $point[$account->id.'_original'] = [
                        'amount' => $originalBalance,
                        'currency_code' => $account->currency_code,
                    ];
                }

                if ($account->type->supportsInvestedAmount()) {
                    $investedAmount = $lookup->getInvestedAmountAt($account->id, $date);
                    $point[$account->id.'_invested'] = $investedAmount !== null
                        ? $this->convertBalance($investedAmount, $account->currency_code, $userCurrency, $date->toDateString())
                        : null;
                }
            }

            $points[] = $point;
            $current->addMonth();
        }

        $now = Carbon::now();
        $accountsConfig = $accounts->mapWithKeys(function ($account) use ($userCurrency, $lookup, $now) {
            $config = [
                'id' => $account->id,
                'name' => $account->name,
                'name_iv' => $account->name_iv,
                'encrypted' => $account->encrypted,
                'type' => $account->type,
                'currency_code' => $account->currency_code,
                'bank' => $account->bank,
                'banking_connection_id' => $account->banking_connection_id,
            ];

            if ($account->type === AccountType::RealEstate && $account->relationLoaded('realEstateDetail') && $account->realEstateDetail?->linked_loan_account_id) {
                $config['linked_loan_account_id'] = $account->realEstateDetail->linked_loan_account_id;
            }

            if ($account->type->supportsInvestedAmount()) {
                $investedAmount = $lookup->getInvestedAmountAt($account->id, $now);
                $config['invested_amount'] = $investedAmount !== null
                    ? $this->convertBalance($investedAmount, $account->currency_code, $userCurrency, $now->toDateString())
                    : null;
            }

            return [$account->id => $config];
        });

        return [
            'data' => $points,
            'accounts' => $accountsConfig,
            'currency_code' => $userCurrency,
        ];
    }

    /**
     * Build daily net worth evolution data points for all accounts.
     *
     * Each point contains the date, timestamp, and per-account converted balances.
     * Accounts with a different currency than the user also get `_original` entries.
     *
     * @param  Collection<int, Account>  $accounts
     * @return array{data: list<array<string, mixed>>, accounts: mixed, currency_code: string}
     */
    public function getNetWorthDailyEvolution(string $userCurrency, Collection $accounts, Carbon $start, Carbon $end): array
    {
        $accountIds = $accounts->pluck('id');
        $lookup = BalanceLookup::forAccounts($accountIds, $start, $end);

        $points = [];
        $current = $start->copy();

        while ($current->lte($end)) {
            $date = $current->copy();
            $point = [
                'date' => $date->format('Y-m-d'),
                'timestamp' => $date->endOfDay()->timestamp,
            ];

            foreach ($accounts as $account) {
                $originalBalance = $lookup->getBalanceAt($account->id, $date);
                $convertedBalance = $this->convertBalance(
                    $originalBalance,
                    $account->currency_code,
                    $userCurrency,
                    $date->toDateString(),
                );

                $point[$account->id] = $convertedBalance;

                if ($account->currency_code !== $userCurrency) {
                    $point[$account->id.'_original'] = [
                        'amount' => $originalBalance,
                        'currency_code' => $account->currency_code,
                    ];
                }
            }

            $points[] = $point;
            $current->addDay();
        }

        $accountsConfig = $accounts->mapWithKeys(function ($account) {
            return [
                $account->id => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'name_iv' => $account->name_iv,
                    'encrypted' => $account->encrypted,
                    'type' => $account->type,
                    'currency_code' => $account->currency_code,
                    'bank' => $account->bank,
                ],
            ];
        });

        return [
            'data' => $points,
            'accounts' => $accountsConfig,
            'currency_code' => $userCurrency,
        ];
    }

    /**
     * Convert a balance from one currency to another.
     */
    private function convertBalance(int $balance, string $sourceCurrency, string $targetCurrency, string $date): int
    {
        if (strtolower($sourceCurrency) === strtolower($targetCurrency)) {
            return $balance;
        }

        return $this->exchangeRateService->convert($sourceCurrency, $targetCurrency, $balance, $date);
    }

    /**
     * Format a month label: "M" for current year, "M 'y" for previous years.
     */
    private function formatMonth(Carbon $date): string
    {
        $isCurrentYear = $date->year === Carbon::now()->year;

        return $isCurrentYear
            ? $date->format('M')
            : $date->format("M 'y");
    }
}
