<?php

namespace App\Support;

/**
 * Values keyed by day, sorted by that day, answering "what was the latest one
 * on or before this date" with a binary search.
 *
 * Days are `Y-m-d` strings: that format orders the same as a string and as a
 * date, so no Carbon instance is built to compare them, and a caller asking
 * about every day of a year does not walk the whole list each time.
 */
readonly class DatedSeries
{
    /**
     * @param  list<string>  $dates  Sorted ascending, one per value.
     * @param  list<int>  $values
     */
    public function __construct(
        private array $dates = [],
        private array $values = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->dates === [];
    }

    public function firstDate(): ?string
    {
        return $this->dates[0] ?? null;
    }

    public function firstValue(): ?int
    {
        return $this->values[0] ?? null;
    }

    /**
     * The value of the last day on or before the given one, null when every
     * day comes after it.
     */
    public function valueOnOrBefore(string $date): ?int
    {
        $low = 0;
        $high = count($this->dates) - 1;
        $found = null;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);

            if ($this->dates[$middle] <= $date) {
                $found = $middle;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $found === null ? null : $this->values[$found];
    }
}
