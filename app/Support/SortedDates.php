<?php

namespace App\Support;

class SortedDates
{
    /**
     * The position of the last date on or before the given one, or -1 when
     * every date comes after it.
     *
     * The dates must be `Y-m-d` strings sorted ascending: that format orders
     * the same as a string and as a date, so no Carbon instance is built to
     * compare them. A binary search, so a caller asking about every day of a
     * year does not walk the whole list each time.
     *
     * @param  list<string>  $dates
     */
    public static function lastIndexOnOrBefore(array $dates, string $date): int
    {
        $low = 0;
        $high = count($dates) - 1;
        $found = -1;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);

            if ($dates[$middle] <= $date) {
                $found = $middle;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $found;
    }
}
