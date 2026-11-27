<?php

namespace App\Enums;

use App\Services\Forecast\ForecastCalendar;

/**
 * How often a forecast line repeats from its anchor date: every week, or
 * every so many calendar months.
 */
enum ForecastFrequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Bimonthly = 'bimonthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';
    case Once = 'once';

    /**
     * Months between two occurrences; null for a line that happens once or
     * does not repeat by whole months (weekly).
     */
    public function months(): ?int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Bimonthly => 2,
            self::Quarterly => 3,
            self::Yearly => 12,
            self::Weekly, self::Once => null,
        };
    }

    /**
     * How many times a year the line happens; null for a line that happens once.
     */
    public function perYear(): ?int
    {
        return match ($this) {
            self::Weekly => 52,
            self::Once => null,
            default => intdiv(12, (int) $this->months()),
        };
    }

    /**
     * The date of the occurrence `$period` steps after `$anchor`; null for a
     * line that happens once.
     */
    public function occurrence(string $anchor, int $period): ?string
    {
        if ($this === self::Weekly) {
            return ForecastCalendar::addDays($anchor, $period * 7);
        }

        $months = $this->months();

        return $months === null ? null : ForecastCalendar::addMonths($anchor, $period * $months);
    }
}
