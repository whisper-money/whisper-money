<?php

namespace App\Services\Forecast;

use App\Models\ForecastItem;
use App\Models\ForecastMatch;
use App\Models\User;

/**
 * Which months of the forecast a user can change. Closed months never: they
 * are what really happened. The month in progress only for what is still
 * to come, so a line a transaction already paid stays as it was. Later
 * months fully, up to the last one the user sees: without Pro that is the
 * next month, the same limit the page draws, enforced here as well.
 */
class ForecastEditWindow
{
    /**
     * Future months a user without Pro sees, and so can edit.
     */
    public const FREE_MONTHS = 1;

    public function firstMonth(User $user): string
    {
        return ForecastCalendar::monthKey($user->localToday()->toDateString());
    }

    /**
     * The last `Y-m` month the user may change; null when there is no limit.
     */
    public function lastMonth(User $user): ?string
    {
        if ($user->hasProPlan()) {
            return null;
        }

        return ForecastCalendar::monthKey(ForecastCalendar::monthStart($user->localToday()->toDateString(), self::FREE_MONTHS));
    }

    public function allowsMonth(User $user, string $month): bool
    {
        $last = $this->lastMonth($user);

        return $month >= $this->firstMonth($user) && ($last === null || $month <= $last);
    }

    /**
     * A new line, or one moved to another day, starts on or after today.
     */
    public function allowsNewDate(User $user, string $date): bool
    {
        return $date >= $user->localToday()->toDateString() && $this->allowsMonth($user, ForecastCalendar::monthKey($date));
    }

    /**
     * Whether one occurrence of a line can change: its month is open and no
     * transaction paid it yet.
     */
    public function allowsOccurrence(User $user, ForecastItem $item, string $occurrenceDate): bool
    {
        return $this->allowsMonth($user, ForecastCalendar::monthKey($occurrenceDate))
            && ! ForecastMatch::query()->where('forecast_item_id', $item->id)->whereDate('occurrence_date', $occurrenceDate)->exists();
    }
}
