<?php

namespace App\Enums;

/**
 * How one month of a monthly savings goal went.
 *
 * A partial month (the goal was created in its last days) and an archived one
 * (the goal was archived during it) are shown with what was saved but get no
 * verdict: holding either to a whole month's target would be unfair.
 */
enum SavingsGoalMonthStatus: string
{
    case Met = 'met';
    case Missed = 'missed';
    case InProgress = 'in_progress';
    case Partial = 'partial';
    case Archived = 'archived';

    /**
     * Whether the month counts towards months met, the streak and the totals.
     */
    public function isJudged(): bool
    {
        return $this === self::Met || $this === self::Missed;
    }
}
