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
 * Each day takes that day's occurrences and an even share of the month's
 * estimated spend (in the start month, what is left of it over the days left);
 * a transfer takes money from one account and gives it to the other, so it
 * leaves the total untouched. An account with a yearly rate grows by a twelfth
 * of it on the close of every whole month it covers (the month the projection
 * starts in is partial, so it does not grow). All in integer minor units, so
 * the TypeScript port in resources/js/lib/forecast/project.ts gives the same
 * figures, which tests/Fixtures/forecast-parity.json checks. Exact while
 * amounts times 31 (or times a rate in basis points) stay below 2^53.
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
     * @param  array<string, int>  $estimates
     * @return list<MonthAccumulator>
     */
    private function projectAccount(ProjectionAccount $account, array $months, array $flows, array $estimates): array
    {
        $balance = $account->openingBalance;
        $accumulators = [];

        foreach ($months as $month) {
            $accumulator = $this->projectMonth($account, $month, $balance, $flows, max($estimates[$month->key] ?? 0, 0));
            $balance = $accumulator->close;
            $accumulators[] = $accumulator;
        }

        return $accumulators;
    }

    /**
     * @param  array<string, array{income: int, expenses: int, transfers: int}>  $flows
     */
    private function projectMonth(ProjectionAccount $account, CalendarMonth $month, int $balance, array $flows, int $estimate): MonthAccumulator
    {
        $accumulator = new MonthAccumulator($month->key);
        $accumulator->estimated = $estimate;

        for ($day = $month->firstDay; $day <= $month->lastDay; $day++) {
            $date = $month->date($day);
            $balance += $accumulator->addFlow($flows[$date] ?? null);
            $balance -= $this->estimateShare($estimate, $day - $month->firstDay + 1, $month->days());

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
     * The estimate's share for the `$day`-th of `$days` days: the running
     * total is floored each day, so the shares are whole and add up exactly.
     */
    private function estimateShare(int $estimate, int $day, int $days): int
    {
        return (int) (floor($estimate * $day / $days) - floor($estimate * ($day - 1) / $days));
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
     * Adjustments may lower an estimate, but a month never spends less than
     * nothing: projectAccount() floors each month's sum at zero.
     *
     * @param  list<MonthlyEstimate>  $estimates
     * @return array<string, array<string, int>> Amount by account, then month.
     */
    private function estimatesByAccount(array $estimates): array
    {
        $byAccount = [];

        foreach ($estimates as $estimate) {
            $byAccount[$estimate->accountId][$estimate->month] = ($byAccount[$estimate->accountId][$estimate->month] ?? 0) + $estimate->amount;
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
