<?php

namespace App\Enums;

use App\Services\MonthlySummary\CardPicker;

/**
 * The shareable cards a monthly summary can produce. The order of the cases is
 * the order {@see CardPicker} tries them in: the
 * first one whose condition holds wins, and SpendingSplit closes the list
 * because there is always a spending split to draw.
 */
enum MonthlySummaryCard: string
{
    case Streak = 'streak';
    case SavingsRate = 'savings_rate';
    case SavingsGoal = 'savings_goal';
    case NetWorth = 'net_worth';
    case SpendingSplit = 'spending_split';

    /**
     * Whether the card can be drawn from a single month, with nothing to compare
     * against. Only these two are offered on a first-month summary.
     */
    public function worksWithoutHistory(): bool
    {
        return match ($this) {
            self::SavingsRate, self::SpendingSplit => true,
            self::Streak, self::SavingsGoal, self::NetWorth => false,
        };
    }
}
