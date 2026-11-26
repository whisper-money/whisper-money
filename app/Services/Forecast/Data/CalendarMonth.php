<?php

namespace App\Services\Forecast\Data;

use App\Services\Forecast\ForecastCalendar;

/**
 * A month of a projection, from `firstDay` (the start date, in the month the
 * projection starts in; the 1st otherwise) to its last day.
 */
final readonly class CalendarMonth
{
    public function __construct(
        public string $key,
        private int $year,
        private int $month,
        public int $firstDay,
        public int $lastDay,
    ) {}

    public static function startingOn(string $date): self
    {
        [$year, $month, $day] = ForecastCalendar::parse($date);

        return new self(ForecastCalendar::monthKey($date), $year, $month, $day, ForecastCalendar::daysInMonth($year, $month));
    }

    /**
     * How many days of the month the projection covers.
     */
    public function days(): int
    {
        return $this->lastDay - $this->firstDay + 1;
    }

    /**
     * Whether the projection covers the month from its 1st.
     */
    public function isWhole(): bool
    {
        return $this->firstDay === 1;
    }

    public function date(int $day): string
    {
        return ForecastCalendar::format($this->year, $this->month, $day);
    }
}
