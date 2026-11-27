<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Account;

/**
 * The forecast shape get_forecast returns, read from
 * ForecastPresenter::overview(): one entry per account in its own currency,
 * and the total of all of them in the user's. Amounts are in minor units.
 */
trait PresentsForecast
{
    /**
     * @param  array{account: Account, threshold: int|null, months: list<array<string, int|string|null>>, end: array<string, int|string|null>, lowest: array<string, int|string|null>, levels: array<string, string>, alert: array{level: string, month: string|null, min: int|null, minDate: string|null, softMonths: list<string>}, causes: array<string, list<array{name: string, amount: int}>>, lockedAlertCount: int|null}  $forecast
     * @return array<string, mixed>
     */
    protected function presentForecastAccount(array $forecast): array
    {
        $account = $forecast['account'];
        $alert = $forecast['alert'];

        return [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type->value,
            'currency' => $account->currency_code,
            'alert_threshold' => $forecast['threshold'],
            'summary' => $this->forecastSummary($forecast),
            'alert' => [
                'level' => $alert['level'],
                'strong_month' => $alert['month'],
                'strong_min' => $alert['min'],
                'strong_min_date' => $alert['minDate'],
                'soft_months' => $alert['softMonths'],
                'locked_strong_month_count' => $forecast['lockedAlertCount'],
            ],
            'months' => array_map(fn (array $month): array => $this->forecastMonth($month, $forecast['levels'], $forecast['causes']), $forecast['months']),
        ];
    }

    /**
     * @param  array<string, int|string|null>  $month
     * @param  array<string, string>  $levels  By `Y-m` month.
     * @param  array<string, list<array{name: string, amount: int}>>  $causes
     * @return array<string, mixed>
     */
    private function forecastMonth(array $month, array $levels, array $causes): array
    {
        $key = (string) $month['month'];

        return [
            'month' => $key,
            'income' => $month['income'],
            'expenses' => $month['expenses'],
            'estimated_expenses' => $month['estimated'],
            'transfers' => $month['transfers'],
            'min' => $month['min'],
            'min_date' => $month['minDate'],
            'close' => $month['close'],
            'band' => $month['band'],
            'pessimistic_min' => $month['pessimisticMin'],
            'alert_level' => $levels[$key],
            'alert_causes' => $causes[$key] ?? [],
        ];
    }

    /**
     * The "All accounts" total: every included account added up in the
     * user's currency at today's rate, as the page's view of them together.
     * Its warning says whether another account's money covers a shortfall
     * (`split`, with the transfer that fixes it) or the total itself runs
     * short (`short`).
     *
     * @param  array<string, mixed>  $all
     * @return array<string, mixed>
     */
    protected function presentForecastTotal(array $all): array
    {
        $alert = $all['alert'];
        $transfer = $alert['transfer'];

        return [
            'currency' => $all['currencyCode'],
            'balance_today' => $all['balanceToday'],
            'unconverted_currencies' => $all['unconvertedCurrencies'],
            'summary' => $this->forecastSummary($all),
            'alert' => [
                'level' => $alert['level'],
                'account_id' => $alert['accountId'],
                'month' => $alert['month'],
                'min' => $alert['min'],
                'min_date' => $alert['minDate'],
                'soft_months' => $alert['softMonths'],
                'transfer' => $transfer === null ? null : ['from_account_id' => $transfer['fromAccountId'], 'amount' => $transfer['amount'], 'date' => $transfer['date']],
                'locked_strong_month_count' => $all['lockedAlertCount'],
            ],
            'months' => array_map(fn (array $month): array => [
                'month' => $month['month'],
                'income' => $month['income'],
                'expenses' => $month['expenses'],
                'min' => $month['min'],
                'min_date' => $month['minDate'],
                'close' => $month['close'],
                'band' => $month['band'],
            ], $all['months']),
        ];
    }

    /**
     * @param  array{end: array<string, int|string|null>, lowest: array<string, int|string|null>}  $forecast
     * @return array<string, int|string|null>
     */
    private function forecastSummary(array $forecast): array
    {
        return [
            'end_month' => $forecast['end']['month'],
            'end_close' => $forecast['end']['close'],
            'end_band' => $forecast['end']['band'],
            'lowest_month' => $forecast['lowest']['month'],
            'lowest_min' => $forecast['lowest']['min'],
            'lowest_min_date' => $forecast['lowest']['minDate'],
        ];
    }
}
