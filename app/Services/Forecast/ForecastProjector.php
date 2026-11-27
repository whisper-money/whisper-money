<?php

namespace App\Services\Forecast;

use App\Services\Forecast\Data\CalendarMonth;
use App\Services\Forecast\Data\MonthlyEstimate;
use App\Services\Forecast\Data\Occurrence;
use App\Services\Forecast\Data\ProjectionAccount;
use App\Services\Forecast\Data\ProjectionInput;

/**
 * Projects each account's balance day by day from its opening balance, and
 * sums them into a total, month by month.
 *
 * Each day takes that day's occurrences and its share of the month's
 * estimates (in the start month, of what is left of them over the days left):
 * an income estimate lands whole on its day, a spend estimate is spread by its
 * day profile, or evenly without one (see MonthlyEstimate); a transfer takes money from one account and gives it to the other, so it
 * leaves the total untouched. An account with a yearly rate grows by a twelfth
 * of it on the close of every whole month it covers (the month the projection
 * starts in is partial, so it does not grow). All in integer minor units, so
 * the TypeScript port in resources/js/lib/forecast/project.ts gives the same
 * figures, which tests/Fixtures/forecast-parity.json checks. Exact while
 * amounts times a profile's total weight (or times a rate in basis points)
 * stay below 2^53.
 *
 * The forecast pages, jobs and MCP tool of the later phases call it; until
 * then only the tests do.
 *
 * @api
 */
final class ForecastProjector
{
    private const NO_FLOW = ['income' => 0, 'expenses' => 0, 'transfers' => 0];

    /**
     * @return array{accounts: list<array{accountId: string, minimumBalance: int|null, months: list<array<string, int|string|null>>}>, total: list<array<string, int|string|null>>}
     */
    public function project(ProjectionInput $input): array
    {
        $months = $this->months($input->startDate, $input->horizonMonths);
        $flows = $this->flowsByAccount($input->occurrences);
        $estimates = $this->estimatesByAccount($input->estimates);
        $perAccount = array_map(
            fn (ProjectionAccount $account): array => $this->projectAccount($account, $months, $flows[$account->id] ?? [], $estimates[$account->id] ?? []),
            $input->accounts,
        );

        return [
            'accounts' => array_map(
                fn (ProjectionAccount $account, array $accumulators): array => [
                    'accountId' => $account->id,
                    'minimumBalance' => $account->minimumBalance,
                    'months' => $this->toArrays($accumulators),
                ],
                $input->accounts,
                $perAccount,
            ),
            'total' => $this->toArrays($this->total($months, $perAccount)),
        ];
    }

    /**
     * @param  list<MonthAccumulator>  $accumulators
     * @return list<array<string, int|string|null>>
     */
    private function toArrays(array $accumulators): array
    {
        return array_map(fn (MonthAccumulator $month): array => $month->toArray(), $accumulators);
    }

    /**
     * @return list<CalendarMonth>
     */
    private function months(string $startDate, int $horizonMonths): array
    {
        $months = [];

        for ($offset = 0; $offset < $horizonMonths; $offset++) {
            $months[] = CalendarMonth::startingOn($offset === 0 ? $startDate : ForecastCalendar::monthStart($startDate, $offset));
        }

        return $months;
    }

    /**
     * @param  list<CalendarMonth>  $months
     * @param  array<string, array{income: int, expenses: int, transfers: int}>  $flows
     * @param  array<string, list<MonthlyEstimate>>  $estimates  By month.
     * @return list<MonthAccumulator>
     */
    private function projectAccount(ProjectionAccount $account, array $months, array $flows, array $estimates): array
    {
        $balance = $account->openingBalance;
        $accumulators = [];

        foreach ($months as $month) {
            $accumulator = $this->projectMonth($account, $month, $balance, $flows, $estimates[$month->key] ?? []);
            $balance = $accumulator->close;
            $accumulators[] = $accumulator;
        }

        return $accumulators;
    }

    /**
     * @param  array<string, array{income: int, expenses: int, transfers: int}>  $flows
     * @param  list<MonthlyEstimate>  $estimates
     */
    private function projectMonth(ProjectionAccount $account, CalendarMonth $month, int $balance, array $flows, array $estimates): MonthAccumulator
    {
        $accumulator = new MonthAccumulator($month->key);
        $estimated = $this->estimatedByDay($month, $estimates, $accumulator);

        for ($day = $month->firstDay; $day <= $month->lastDay; $day++) {
            $date = $month->date($day);
            $balance += $accumulator->addFlow($flows[$date] ?? null);
            $balance += $estimated[$day] ?? 0;

            if ($day === $month->lastDay && $month->isWhole()) {
                $accumulator->growth = $this->monthlyGrowth($balance, $account->annualRateBps);
                $balance += $accumulator->growth;
            }

            $accumulator->observe($date, $balance);
        }

        return $accumulator;
    }

    /**
     * A twelfth of the yearly rate on the month's close, truncated to whole
     * minor units.
     */
    private function monthlyGrowth(int $balance, ?int $annualRateBps): int
    {
        return intdiv($balance * ($annualRateBps ?? 0), 120_000);
    }

    /**
     * What the month's estimates change the balance by on each day it
     * covers (income up, spend down), recording their totals on the month.
     * A month never expects less than nothing from an estimate.
     *
     * @param  list<MonthlyEstimate>  $estimates
     * @return array<int, int> By day of the month.
     */
    private function estimatedByDay(CalendarMonth $month, array $estimates, MonthAccumulator $accumulator): array
    {
        $byDay = [];

        foreach ($estimates as $estimate) {
            $amount = max($estimate->amount, 0);
            $sign = $estimate->isIncome() ? 1 : -1;
            $accumulator->addEstimate($amount, $estimate->isIncome());

            foreach ($this->spread($amount, $this->weights($estimate, $month)) as $day => $share) {
                $byDay[$day] = ($byDay[$day] ?? 0) + $sign * $share;
            }
        }

        return $byDay;
    }

    /**
     * How an estimate weighs each day the month covers: all on its day for
     * a lump, by its profile otherwise, evenly when that leaves no weight.
     *
     * @return array<int, int> By day of the month.
     */
    private function weights(MonthlyEstimate $estimate, CalendarMonth $month): array
    {
        if ($estimate->day !== null) {
            return [max(min($estimate->day, $month->lastDay), $month->firstDay) => 1];
        }

        $days = range($month->firstDay, $month->lastDay);
        $profile = $estimate->profile;
        $weights = array_combine($days, array_map(
            fn (int $day): int => $profile === null ? 1 : $this->profileWeight($profile, $day, $month->lastDay),
            $days,
        ));

        return array_sum($weights) > 0 ? $weights : array_fill_keys($days, 1);
    }

    /**
     * A day's profile weight; the month's last day also takes the days a
     * short month lacks.
     *
     * @param  list<int>  $profile
     */
    private function profileWeight(array $profile, int $day, int $lastDay): int
    {
        return $day < $lastDay ? max($profile[$day - 1] ?? 0, 0) : array_sum(array_map(fn (int $weight): int => max($weight, 0), array_slice($profile, $day - 1)));
    }

    /**
     * The amount split by the weights: the running total is floored each
     * day, so the shares are whole and add up exactly.
     *
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    private function spread(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        $running = 0;
        $shares = [];

        foreach ($weights as $day => $weight) {
            $before = $running;
            $running += $weight;
            $shares[$day] = intdiv($amount * $running, $total) - intdiv($amount * $before, $total);
        }

        return $shares;
    }

    /**
     * Each account's concrete flows by day, in one pass: a transfer counts on
     * both its accounts.
     *
     * @param  list<Occurrence>  $occurrences
     * @return array<string, array<string, array{income: int, expenses: int, transfers: int}>>
     */
    private function flowsByAccount(array $occurrences): array
    {
        $flows = [];

        foreach ($occurrences as $occurrence) {
            $flow = $flows[$occurrence->accountId][$occurrence->date] ?? self::NO_FLOW;
            $flow[$this->flowKind($occurrence)] += $occurrence->isTransfer() ? $occurrence->amount : abs($occurrence->amount);
            $flows[$occurrence->accountId][$occurrence->date] = $flow;

            if ($occurrence->destinationAccountId !== null) {
                $flow = $flows[$occurrence->destinationAccountId][$occurrence->date] ?? self::NO_FLOW;
                $flow['transfers'] -= $occurrence->amount;
                $flows[$occurrence->destinationAccountId][$occurrence->date] = $flow;
            }
        }

        return $flows;
    }

    /**
     * @return 'income'|'expenses'|'transfers'
     */
    private function flowKind(Occurrence $occurrence): string
    {
        if ($occurrence->isTransfer()) {
            return 'transfers';
        }

        return $occurrence->amount >= 0 ? 'income' : 'expenses';
    }

    /**
     * @param  list<MonthlyEstimate>  $estimates
     * @return array<string, array<string, list<MonthlyEstimate>>> By account, then month.
     */
    private function estimatesByAccount(array $estimates): array
    {
        $byAccount = [];

        foreach ($estimates as $estimate) {
            $byAccount[$estimate->accountId][$estimate->month][] = $estimate;
        }

        return $byAccount;
    }

    /**
     * @param  list<CalendarMonth>  $months
     * @param  list<list<MonthAccumulator>>  $perAccount
     * @return list<MonthAccumulator>
     */
    private function total(array $months, array $perAccount): array
    {
        $totals = [];

        foreach ($months as $index => $month) {
            $total = new MonthAccumulator($month->key);
            $balances = [];

            foreach ($perAccount as $accumulators) {
                $total->absorbFlows($accumulators[$index]);

                foreach ($accumulators[$index]->balances as $date => $balance) {
                    $balances[$date] = ($balances[$date] ?? 0) + $balance;
                }
            }

            foreach ($balances as $date => $balance) {
                $total->observe($date, $balance);
            }

            $totals[] = $total;
        }

        return $totals;
    }
}
