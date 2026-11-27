<?php

namespace App\Services\Forecast;

/**
 * When in the month a category's estimate lands, learnt from the months the
 * median is taken over. Income comes as one lump on its usual day: each
 * month's payday is the day most of that month's income came in, and the
 * estimate lands on the median of those days. Spend follows its day
 * profile: the share of the category's spend each day of the month took,
 * as integer weights adding up to PROFILE_TOTAL so the projector splits
 * with exact integers. Under MIN_PROFILE_MONTHS months of history a
 * profile says little, so the spend is spread evenly (null). See
 * Data/MonthlyEstimate for how the projector reads both.
 */
final class EstimateTiming
{
    private const PROFILE_TOTAL = 10_000;

    private const MIN_PROFILE_MONTHS = 2;

    private const DAYS = 31;

    /**
     * @param  array<string, array<int, int>>  $byMonthAndDay  Amounts by `Y-m` month, then day of the month.
     * @param  list<string>  $pastMonths  The months that count.
     * @return int|null Null when no month brought anything in.
     */
    public function incomeDay(array $byMonthAndDay, array $pastMonths): ?int
    {
        $paydays = [];

        foreach ($pastMonths as $month) {
            $days = array_filter($byMonthAndDay[$month] ?? [], fn (int $amount): bool => $amount > 0);

            if ($days !== []) {
                $paydays[] = array_search(max($days), $days, true);
            }
        }

        if ($paydays === []) {
            return null;
        }

        sort($paydays);
        $middle = intdiv(count($paydays), 2);

        return count($paydays) % 2 === 1 ? $paydays[$middle] : intdiv($paydays[$middle - 1] + $paydays[$middle], 2);
    }

    /**
     * @param  array<string, array<int, int>>  $byMonthAndDay  Amounts by `Y-m` month, then day of the month.
     * @param  list<string>  $pastMonths  The months that count.
     * @return list<int>|null One weight per day of the month (the 1st first), or null to spread evenly.
     */
    public function expenseProfile(array $byMonthAndDay, array $pastMonths): ?array
    {
        if (count($pastMonths) < self::MIN_PROFILE_MONTHS) {
            return null;
        }

        $byDay = array_fill(1, self::DAYS, 0);

        foreach ($pastMonths as $month) {
            foreach ($byMonthAndDay[$month] ?? [] as $day => $amount) {
                $byDay[$day] += $amount;
            }
        }

        $byDay = array_map(fn (int $amount): int => max($amount, 0), $byDay);
        $total = array_sum($byDay);

        return $total > 0 ? $this->normalised($byDay, $total) : null;
    }

    /**
     * The amounts as weights adding up to PROFILE_TOTAL: each floored, what
     * the flooring loses going to the heaviest day.
     *
     * @param  array<int, int>  $byDay  Keyed 1 to 31.
     * @return list<int>
     */
    private function normalised(array $byDay, int $total): array
    {
        $weights = array_values(array_map(fn (int $amount): int => intdiv($amount * self::PROFILE_TOTAL, $total), $byDay));
        $heaviest = array_search(max($byDay), array_values($byDay), true);
        $weights[$heaviest] += self::PROFILE_TOTAL - array_sum($weights);

        return $weights;
    }
}
