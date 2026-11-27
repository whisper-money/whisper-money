<?php

namespace App\Services\Forecast;

/**
 * Calendar arithmetic on `Y-m-d` strings, kept to plain integers so the
 * TypeScript port (resources/js/lib/forecast/calendar.ts) can match it to the
 * day without a date library or a time zone in the way.
 *
 * The forecast pages, jobs and MCP tool of the later phases call it; until
 * then only the tests do.
 *
 * @api
 */
final class ForecastCalendar
{
    public static function daysInMonth(int $year, int $month): int
    {
        if ($month === 2) {
            return self::isLeapYear($year) ? 29 : 28;
        }

        return in_array($month, [4, 6, 9, 11], true) ? 30 : 31;
    }

    private static function isLeapYear(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }

    public static function format(int $year, int $month, int $day): string
    {
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function parse(string $date): array
    {
        [$year, $month, $day] = array_map(intval(...), explode('-', $date));

        return [$year, $month, $day];
    }

    /**
     * The date `$months` calendar months after `$anchor`, on the anchor's day
     * of the month, or on the month's last day when it is shorter: an anchor
     * on the 31st lands on 28 February and back on 31 March.
     */
    public static function addMonths(string $anchor, int $months): string
    {
        [$year, $month, $day] = self::parse($anchor);
        $index = $year * 12 + ($month - 1) + $months;
        $targetYear = intdiv($index, 12);
        $targetMonth = $index % 12 + 1;

        return self::format($targetYear, $targetMonth, min($day, self::daysInMonth($targetYear, $targetMonth)));
    }

    /**
     * The date `$days` days after `$anchor`, or before it when negative.
     */
    public static function addDays(string $anchor, int $days): string
    {
        [$year, $month, $day] = self::parse($anchor);

        return gmdate('Y-m-d', gmmktime(0, 0, 0, $month, $day + $days, $year));
    }

    /**
     * The last day a projection of `$horizonMonths` months from `$startDate`
     * covers: the end of the `$horizonMonths`-th month, counting the first.
     */
    public static function horizonEnd(string $startDate, int $horizonMonths): string
    {
        [$year, $month] = self::parse(self::monthStart($startDate, $horizonMonths - 1));

        return self::format($year, $month, self::daysInMonth($year, $month));
    }

    /**
     * The first day of the month `$offset` months after the one `$date` is in.
     */
    public static function monthStart(string $date, int $offset = 0): string
    {
        return self::addMonths(substr($date, 0, 8).'01', $offset);
    }

    /**
     * The date in `$date`'s month on `$day`, or on its last day when shorter.
     */
    public static function onDay(string $date, int $day): string
    {
        [$year, $month] = self::parse($date);

        return self::format($year, $month, min($day, self::daysInMonth($year, $month)));
    }

    public static function nextDay(string $date): string
    {
        [$year, $month, $day] = self::parse($date);

        return $day < self::daysInMonth($year, $month) ? self::format($year, $month, $day + 1) : self::monthStart($date, 1);
    }

    public static function previousDay(string $date): string
    {
        [$year, $month, $day] = self::parse($date);

        if ($day > 1) {
            return self::format($year, $month, $day - 1);
        }

        [$previousYear, $previousMonth] = self::parse(self::monthStart($date, -1));

        return self::format($previousYear, $previousMonth, self::daysInMonth($previousYear, $previousMonth));
    }

    /**
     * The `Y-m` key of a date.
     */
    public static function monthKey(string $date): string
    {
        return substr($date, 0, 7);
    }
}
