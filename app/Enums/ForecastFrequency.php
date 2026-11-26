<?php

namespace App\Enums;

/**
 * How often a forecast line repeats, counted in calendar months from its
 * anchor date.
 */
enum ForecastFrequency: string
{
    case Monthly = 'monthly';
    case Bimonthly = 'bimonthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';
    case Once = 'once';

    /**
     * Months between two occurrences; null for a line that happens once.
     */
    public function months(): ?int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Bimonthly => 2,
            self::Quarterly => 3,
            self::Yearly => 12,
            self::Once => null,
        };
    }
}
