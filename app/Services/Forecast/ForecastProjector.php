<?php

namespace App\Services\Forecast;

use App\Services\Forecast\Data\MonthlyEstimate;
use App\Services\Forecast\Data\MonthSummary;
use App\Services\Forecast\Data\Occurrence;
use App\Services\Forecast\Data\ProjectionAccount;
use App\Services\Forecast\Data\ProjectionInput;

/**
 * Projects each account's balance day by day from its opening balance, and
 * sums them into a total, month by month.
 *
 * Each day takes that day's occurrences and an even share of the month's
 * estimated spend; a transfer takes money from one account and gives it to
 * the other, so it leaves the total untouched. An account with a yearly rate
 * grows by a twelfth of it on the close of every whole month it covers (the
 * month the projection starts in is partial, so it does not grow). All in
 * integer minor units, so the TypeScript port in resources/js/lib/forecast/
 * project.ts gives the same figures, which tests/Fixtures/forecast-parity.json
 * checks.
 */
final class ForecastProjector
{
    /**
     * @return array{accounts: list<array{accountId: string, minimumBalance: int|null, months: list<array<string, int|string|null>>}>, total: list<array<string, int|string|null>>}
     */
    public function project(ProjectionInput $input): array
    {
        $months = $this->months($input->startDate, $input->horizonMonths);
        $perAccount = array_map(
            fn (ProjectionAccount $account): array => $this->projectAccount($account, $months, $input),
            $input->accounts,
        );

        return [
            'accounts' => array_map(
                fn (ProjectionAccount $account, array $summaries): array => [
                    'accountId' => $account->id,
                    'minimumBalance' => $account->minimumBalance,
                    'months' => $this->toArrays($summaries),
                ],
                $input->accounts,
                $perAccount,
            ),
            'total' => $this->toArrays($this->total($months, $perAccount)),
        ];
    }

    /**
     * @param  list<MonthSummary>  $summaries
     * @return list<array<string, int|string|null>>
     */
    private function toArrays(array $summaries): array
    {
        return array_map(fn (MonthSummary $month): array => $month->toArray(), $summaries);
    }

    /**
     * @return list<array{key: string, year: int, month: int, firstDay: int, lastDay: int}>
     */
    private function months(string $startDate, int $horizonMonths): array
    {
        $months = [];

        for ($offset = 0; $offset < $horizonMonths; $offset++) {
            $first = $offset === 0 ? $startDate : ForecastCalendar::monthStart($startDate, $offset);
            [$year, $month, $day] = ForecastCalendar::parse($first);
            $months[] = [
                'key' => ForecastCalendar::monthKey($first),
                'year' => $year,
                'month' => $month,
                'firstDay' => $day,
                'lastDay' => ForecastCalendar::daysInMonth($year, $month),
            ];
        }

        return $months;
    }

    /**
     * @param  list<array{key: string, year: int, month: int, firstDay: int, lastDay: int}>  $months
     * @return list<MonthSummary>
     */
    private function projectAccount(ProjectionAccount $account, array $months, ProjectionInput $input): array
    {
        $flows = $this->dailyFlows($account->id, $input->occurrences);
        $estimates = $this->estimatesByMonth($account->id, $input->estimates);
        $balance = $account->openingBalance;
        $summaries = [];

        foreach ($months as $month) {
            $summary = $this->projectMonth($account, $month, $balance, $flows, $estimates[$month['key']] ?? 0);
            $balance = $summary->close;
            $summaries[] = $summary;
        }

        return $summaries;
    }

    /**
     * @param  array{key: string, year: int, month: int, firstDay: int, lastDay: int}  $month
     * @param  array<string, array{income: int, expenses: int, transfers: int}>  $flows
     */
    private function projectMonth(ProjectionAccount $account, array $month, int $balance, array $flows, int $estimate): MonthSummary
    {
        $summary = new MonthSummary($month['key']);
        $summary->estimated = $estimate;
        $days = $month['lastDay'] - $month['firstDay'] + 1;

        for ($day = $month['firstDay']; $day <= $month['lastDay']; $day++) {
            $date = ForecastCalendar::format($month['year'], $month['month'], $day);
            $balance += $summary->addFlow($flows[$date] ?? null);
            $balance -= $this->estimateShare($estimate, $day - $month['firstDay'] + 1, $days);

            if ($day === $month['lastDay'] && $month['firstDay'] === 1) {
                $summary->growth = $this->monthlyGrowth($balance, $account->annualRateBps);
                $balance += $summary->growth;
            }

            $summary->observe($date, $balance);
        }

        return $summary;
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
     * @param  list<Occurrence>  $occurrences
     * @return array<string, array{income: int, expenses: int, transfers: int}>
     */
    private function dailyFlows(string $accountId, array $occurrences): array
    {
        $flows = [];

        foreach ($occurrences as $occurrence) {
            $flows[$occurrence->date] ??= ['income' => 0, 'expenses' => 0, 'transfers' => 0];

            if ($occurrence->accountId === $accountId) {
                $flows[$occurrence->date][$this->flowKind($occurrence)] += $occurrence->isTransfer() ? $occurrence->amount : abs($occurrence->amount);
            }

            if ($occurrence->destinationAccountId === $accountId) {
                $flows[$occurrence->date]['transfers'] -= $occurrence->amount;
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
     * @return array<string, int>
     */
    private function estimatesByMonth(string $accountId, array $estimates): array
    {
        $byMonth = [];

        foreach ($estimates as $estimate) {
            if ($estimate->accountId === $accountId) {
                $byMonth[$estimate->month] = ($byMonth[$estimate->month] ?? 0) + $estimate->amount;
            }
        }

        return $byMonth;
    }

    /**
     * @param  list<array{key: string, year: int, month: int, firstDay: int, lastDay: int}>  $months
     * @param  list<list<MonthSummary>>  $perAccount
     * @return list<MonthSummary>
     */
    private function total(array $months, array $perAccount): array
    {
        $totals = [];

        foreach ($months as $index => $month) {
            $total = new MonthSummary($month['key']);
            $balances = [];

            foreach ($perAccount as $summaries) {
                $total->absorbFlows($summaries[$index]);

                foreach ($summaries[$index]->balances as $date => $balance) {
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
