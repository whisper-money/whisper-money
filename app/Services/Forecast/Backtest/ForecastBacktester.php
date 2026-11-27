<?php

namespace App\Services\Forecast\Backtest;

use App\Enums\ForecastEstimateKind;
use App\Models\Account;
use App\Models\User;
use App\Services\BalanceLookup;
use App\Services\Forecast\Data\CategoryEstimate;
use App\Services\Forecast\EstimateCalculator;
use App\Services\Forecast\ForecastAccounts;
use App\Services\Forecast\ForecastCalendar;
use App\Services\Forecast\ForecastHistory;
use App\Services\Forecast\ForecastPlanBuilder;
use App\Services\Forecast\ForecastProjector;
use App\Services\Forecast\MonthAccumulator;
use App\Services\Forecast\TransferPairer;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Builds a user's forecast as it would have been at each cut-off, from what
 * was known then, and sets it next to what really happened, along with two
 * baselines: the balance staying flat, and the balance moving by the average
 * net flow of the three complete months before the cut-off.
 *
 * A cut-off is the first day of a month and the forecast starts on it: that
 * day's balance is its opening, and the months after it are compared at
 * their close (the month of the cut-off itself is the first). Only accounts
 * whose balance was known for the three complete months before the cut-off
 * are measured, so a forecast is never judged on months it had nothing to
 * learn from. What really happened is the balance the app knows at each
 * month end, the last one recorded on or before it. Liquid accounts are
 * measured one by one; the all-accounts total adds up every measured account
 * in the user's currency (nothing is converted, so the backtest never reads
 * exchange rates). Income and spend are compared per row the forecast had
 * (an account's parent category, or its "Other" row) and per account, month
 * by month, over the same money the estimates learn from (transfers between
 * the user's own accounts left out).
 *
 * Read only: it writes nothing, and what it returns tells no user apart.
 */
class ForecastBacktester
{
    /**
     * Months after the cut-off each forecast is measured at.
     *
     * @var list<int>
     */
    private const HORIZONS = [1, 3, 6];

    /**
     * Months the net-flow baseline averages.
     */
    private const AVERAGE_MONTHS = 3;

    /**
     * Balances smaller than this (in major units) are too small for a percentage.
     */
    private const BALANCE_FLOOR = 100;

    /**
     * Monthly flows smaller than this (in major units) are too small for a
     * percentage: the size under which a category folds into "Other".
     */
    private const FLOW_FLOOR = 30;

    public function __construct(
        private readonly ForecastPlanBuilder $builder,
        private readonly ForecastProjector $projector,
        private readonly ForecastAccounts $accounts,
        private readonly ForecastHistory $history,
        private readonly TransferPairer $pairer,
    ) {}

    /**
     * The first day of each month a forecast is built at: for each horizon,
     * the latest `$perHorizon` months that already have that many complete
     * months after them, with the horizons measured at it. Oldest first.
     *
     * @return array<string, list<int>> Horizons by `Y-m-d` cut-off.
     */
    public static function cutoffs(string $today, int $perHorizon): array
    {
        $cutoffs = [];

        foreach (self::HORIZONS as $horizon) {
            foreach (range(0, $perHorizon - 1) as $index) {
                $cutoffs[ForecastCalendar::monthStart($today, -$horizon - $index)][] = $horizon;
            }
        }

        ksort($cutoffs);

        return $cutoffs;
    }

    /**
     * @param  array<string, list<int>>  $cutoffs  The horizons to measure, by cut-off (the first day of a month, `Y-m-d`), oldest first.
     * @param  string  $actualsEnd  The last day (`Y-m-d`) what happened is known for.
     * @return list<BacktestObservation>
     */
    public function observe(User $user, string $spaceId, array $cutoffs, string $actualsEnd): array
    {
        $included = $this->accounts->included($user, $spaceId);

        if ($included->isEmpty() || $cutoffs === []) {
            return [];
        }

        $first = (string) array_key_first($cutoffs);
        $lookup = BalanceLookup::forAccounts($included->modelKeys(), Carbon::parse(self::averageFrom($first)), Carbon::parse($actualsEnd));
        $flows = $this->actualFlows($user, $spaceId, $included, $first, $actualsEnd);
        $observations = [];

        foreach ($cutoffs as $cutoff => $horizons) {
            $cutoff = (string) $cutoff;
            // Only accounts known for the whole three months the estimates learn from.
            $measured = $included->filter(fn (Account $account): bool => $lookup->hasHistoryOn($account->id, Carbon::parse(self::averageFrom($cutoff))))->values();

            if ($measured->isNotEmpty()) {
                array_push($observations, ...$this->observeCutoff($user, $spaceId, $measured, $lookup, $flows, $cutoff, $horizons));
            }
        }

        return $observations;
    }

    /**
     * @param  Collection<int, Account>  $measured
     * @param  array<string, array<string, array<string, array<string, int>>>>  $flows
     * @param  list<int>  $horizons
     * @return list<BacktestObservation>
     */
    private function observeCutoff(User $user, string $spaceId, Collection $measured, BalanceLookup $lookup, array $flows, string $cutoff, array $horizons): array
    {
        $plan = $this->builder->build($user, $spaceId, $measured->modelKeys(), max($horizons), $cutoff);
        $months = array_column($this->projector->project($plan->input)['accounts'], 'months', 'accountId');

        return [
            ...$this->balanceObservations($user, $measured, $months, $lookup, $cutoff, $horizons),
            ...$this->flowObservations($measured, $plan->categoryEstimates, $flows, $cutoff, $horizons),
        ];
    }

    /**
     * @param  Collection<int, Account>  $measured
     * @param  array<string, list<array<string, int|string|null>>>  $months  The projected months, by account id.
     * @param  list<int>  $horizons
     * @return list<BacktestObservation>
     */
    private function balanceObservations(User $user, Collection $measured, array $months, BalanceLookup $lookup, string $cutoff, array $horizons): array
    {
        $observations = [];
        $totals = [];

        foreach ($measured as $account) {
            $opening = $lookup->getBalanceAt($account->id, Carbon::parse($cutoff));
            $netFlow = $this->averageNetFlow($lookup, $account->id, $cutoff);
            $inTotal = strcasecmp($account->currency_code, (string) $user->currency_code) === 0;
            $floor = Money::toMinor(self::BALANCE_FLOOR, $account->currency_code);

            foreach ($horizons as $horizon) {
                $month = $months[$account->id][$horizon - 1];
                $actual = $lookup->getBalanceAt($account->id, Carbon::parse(ForecastCalendar::horizonEnd($cutoff, $horizon)));
                $figures = [
                    BacktestSeries::ACCOUNT => [(int) $month['close'], (int) $month['band']],
                    BacktestSeries::ACCOUNT_FLAT => [$opening, null],
                    BacktestSeries::ACCOUNT_NET_FLOW => [$opening + $horizon * $netFlow, null],
                ];

                foreach ($figures as $series => [$forecast, $band]) {
                    if (ForecastAccounts::isLiquid($account->type)) {
                        $observations[] = new BacktestObservation($series, $horizon, $forecast, $actual, $band, $floor);
                    }

                    if ($inTotal) {
                        $total = $totals[$series][$horizon] ?? [0, 0, $band === null ? null : 0];
                        $totals[$series][$horizon] = [$total[0] + $forecast, $total[1] + $actual, $band === null ? null : $total[2] + $band];
                    }
                }
            }
        }

        return [...$observations, ...$this->totalObservations($totals, Money::toMinor(self::BALANCE_FLOOR, (string) $user->currency_code))];
    }

    /**
     * @param  array<string, array<int, array{int, int, int|null}>>  $totals  Forecast, actual and band, by account series and horizon.
     * @return list<BacktestObservation>
     */
    private function totalObservations(array $totals, int $floor): array
    {
        $series = [
            BacktestSeries::ACCOUNT => BacktestSeries::TOTAL,
            BacktestSeries::ACCOUNT_FLAT => BacktestSeries::TOTAL_FLAT,
            BacktestSeries::ACCOUNT_NET_FLOW => BacktestSeries::TOTAL_NET_FLOW,
        ];
        $observations = [];

        foreach ($totals as $accountSeries => $byHorizon) {
            foreach ($byHorizon as $horizon => [$forecast, $actual, $band]) {
                $observations[] = new BacktestObservation($series[$accountSeries], $horizon, $forecast, $actual, $band, $floor);
            }
        }

        return $observations;
    }

    /**
     * What the account's balance moved by, on average, over the complete
     * months before the cut-off; nothing when its balance was not known
     * that far back.
     */
    private function averageNetFlow(BalanceLookup $lookup, string $accountId, string $cutoff): int
    {
        $from = Carbon::parse(self::averageFrom($cutoff));

        if (! $lookup->hasHistoryOn($accountId, $from)) {
            return 0;
        }

        $moved = $lookup->getBalanceAt($accountId, Carbon::parse(ForecastCalendar::previousDay($cutoff))) - $lookup->getBalanceAt($accountId, $from);

        return (int) round($moved / self::AVERAGE_MONTHS);
    }

    /**
     * The day the net-flow baseline averages from: the last day before its
     * first month.
     */
    private static function averageFrom(string $cutoff): string
    {
        return ForecastCalendar::previousDay(ForecastCalendar::monthStart($cutoff, -self::AVERAGE_MONTHS));
    }

    /**
     * @param  Collection<int, Account>  $measured
     * @param  list<CategoryEstimate>  $estimates
     * @param  array<string, array<string, array<string, array<string, int>>>>  $flows
     * @param  list<int>  $horizons
     * @return list<BacktestObservation>
     */
    private function flowObservations(Collection $measured, array $estimates, array $flows, string $cutoff, array $horizons): array
    {
        $forecast = [];

        foreach ($estimates as $estimate) {
            $row = $estimate->categoryId ?? '';
            $forecast[$estimate->accountId][$estimate->kind->value][$row][$estimate->month] = ($forecast[$estimate->accountId][$estimate->kind->value][$row][$estimate->month] ?? 0) + (int) $estimate->planned;
        }

        $observations = [];

        foreach ($measured as $account) {
            $floor = Money::toMinor(self::FLOW_FLOOR, $account->currency_code);

            foreach (ForecastEstimateKind::cases() as $kind) {
                $rows = $forecast[$account->id][$kind->value] ?? [];
                $actual = self::byForecastRow($flows[$account->id][$kind->value] ?? [], $rows);

                foreach ($horizons as $horizon) {
                    array_push($observations, ...self::monthObservations($kind, $horizon, ForecastCalendar::monthKey(ForecastCalendar::monthStart($cutoff, $horizon - 1)), $rows, $actual, $floor));
                }
            }
        }

        return $observations;
    }

    /**
     * What really moved, regrouped by the rows the forecast had: a parent
     * category without a row of its own went into "Other".
     *
     * @param  array<string, array<string, int>>  $flows  By parent category ('' for none), then month.
     * @param  array<string, array<string, int>>  $rows  The forecast, by row, then month.
     * @return array<string, array<string, int>>
     */
    private static function byForecastRow(array $flows, array $rows): array
    {
        $actual = [];

        foreach ($flows as $parent => $byMonth) {
            $row = isset($rows[$parent]) ? (string) $parent : '';

            foreach ($byMonth as $month => $amount) {
                $actual[$row][$month] = ($actual[$row][$month] ?? 0) + $amount;
            }
        }

        return $actual;
    }

    /**
     * @param  array<string, array<string, int>>  $rows
     * @param  array<string, array<string, int>>  $actual
     * @return list<BacktestObservation>
     */
    private static function monthObservations(ForecastEstimateKind $kind, int $horizon, string $month, array $rows, array $actual, int $floor): array
    {
        $income = $kind === ForecastEstimateKind::Income;
        $observations = [];
        $forecastTotal = 0;
        $actualTotal = 0;

        foreach (array_unique([...array_map(strval(...), array_keys($rows)), ...array_map(strval(...), array_keys($actual))]) as $row) {
            $forecast = $rows[$row][$month] ?? 0;
            $real = max($actual[$row][$month] ?? 0, 0);
            $forecastTotal += $forecast;
            $actualTotal += $real;

            if ($forecast !== 0 || $real !== 0) {
                $observations[] = new BacktestObservation($income ? BacktestSeries::INCOME_CATEGORY : BacktestSeries::EXPENSE_CATEGORY, $horizon, $forecast, $real, self::bandOf($kind, $forecast), $floor);
            }
        }

        if ($forecastTotal !== 0 || $actualTotal !== 0) {
            $observations[] = new BacktestObservation($income ? BacktestSeries::INCOME_MONTH : BacktestSeries::EXPENSE_MONTH, $horizon, $forecastTotal, $actualTotal, self::bandOf($kind, $forecastTotal), $floor);
        }

        return $observations;
    }

    /**
     * The band the forecast puts around an estimate of that size.
     */
    private static function bandOf(ForecastEstimateKind $kind, int $amount): int
    {
        $month = new MonthAccumulator('');
        $month->addEstimate($kind, $amount);

        return $month->band();
    }

    /**
     * What each measured account really moved, by side, parent category
     * ('' for none) and month, over the money the estimates learn from.
     *
     * @param  Collection<int, Account>  $accounts
     * @return array<string, array<string, array<string, array<string, int>>>> By account, side, parent, then `Y-m` month.
     */
    private function actualFlows(User $user, string $spaceId, Collection $accounts, string $from, string $to): array
    {
        $parents = $this->history->parentCategories($user);
        $transactions = $this->history->estimableTransactions($user, $spaceId, $from, $to);
        $legs = $this->pairer->pair($transactions);
        $included = array_flip($accounts->modelKeys());
        $flows = [];

        foreach ($transactions as $transaction) {
            $kind = EstimateCalculator::sideOf($transaction);

            if ($kind === null || ! isset($included[$transaction->account_id]) || ($legs[$transaction->id] ?? null)?->isInternal()) {
                continue;
            }

            $parent = $transaction->category_id === null ? '' : ($parents[$transaction->category_id] ?? '');
            $month = ForecastCalendar::monthKey($transaction->transaction_date->toDateString());
            $share = $transaction->account->shareOfAmount($transaction->amount);
            $flows[$transaction->account_id][$kind->value][$parent][$month] = ($flows[$transaction->account_id][$kind->value][$parent][$month] ?? 0) + ($kind === ForecastEstimateKind::Income ? $share : -$share);
        }

        return $flows;
    }
}
