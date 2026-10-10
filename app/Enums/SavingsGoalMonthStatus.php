<?php

namespace App\Enums;

/**
 * How one month of a monthly savings goal went.
 *
 * A partial month is shown with what was saved but gets no verdict: the goal
 * was created in its last days, or archived during it, so holding it to a
 * whole month's target would be unfair either way.
 */
enum SavingsGoalMonthStatus: string
{
    case Met = 'met';
    case Missed = 'missed';
    case InProgress = 'in_progress';
    case Partial = 'partial';

    /**
     * Whether the month counts towards months met, the streak and the totals.
     */
    public function isJudged(): bool
    {
        return $this === self::Met || $this === self::Missed;
    }
}
