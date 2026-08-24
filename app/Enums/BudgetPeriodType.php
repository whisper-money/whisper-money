<?php

namespace App\Enums;

enum BudgetPeriodType: string
{
    case Monthly = 'monthly';
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Weekly => 'Weekly',
            self::Biweekly => 'Bi-weekly',
            self::Yearly => 'Yearly',
        };
    }

    /**
     * Validation rules for `period_start_day`, whose meaning is this enum.
     *
     * A day of the week for the two week-based periods, where 0 is Sunday, and a
     * day of the month otherwise. Accepting the union of both ranges is what let
     * three monthly budgets be stored with day 0, which `Carbon::day(0)` reads as
     * the last day of the previous month and which froze their period chains.
     * Yearly ignores the column - `calculatePeriodDates` starts its window on 1
     * January - so its range is the harmless one.
     *
     * @return array<int, string>
     */
    public function startDayRules(): array
    {
        return match ($this) {
            self::Weekly, self::Biweekly => ['min:0', 'max:6'],
            self::Monthly, self::Yearly => ['min:1', 'max:31'],
        };
    }
}
