<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * How often a credit card statement closes: once a month on the same day, or
 * once a week on the same weekday.
 */
enum StatementFrequency: string
{
    case Monthly = 'monthly';
    case Weekly = 'weekly';

    /**
     * The longest a statement can take to be charged after it closes. Banks
     * give a few days to a few weeks on a monthly card; a weekly one is charged
     * before the week after it closes is over.
     */
    public function maxDaysToPay(): int
    {
        return match ($this) {
            self::Monthly => 45,
            self::Weekly => 7,
        };
    }

    /**
     * $date moved $cycles statements away (negative goes back). Months never
     * overflow, so a closing on the 31st lands on the last day of short months.
     */
    public function addCycles(CarbonImmutable $date, int $cycles): CarbonImmutable
    {
        return match ($this) {
            self::Monthly => $date->addMonthsNoOverflow($cycles),
            self::Weekly => $date->addWeeks($cycles),
        };
    }

    /**
     * A first guess at how many statements separate $from and $to, close
     * enough for a couple of correction steps to finish the job.
     */
    public function approximateCyclesBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return match ($this) {
            self::Monthly => ($to->year - $from->year) * 12 + $to->month - $from->month,
            self::Weekly => intdiv((int) $from->diffInDays($to, false), 7),
        };
    }
}
