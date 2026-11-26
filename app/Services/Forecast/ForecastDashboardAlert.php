<?php

namespace App\Services\Forecast;

use App\Enums\ForecastAlertLevel;
use App\Models\ForecastAlert;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * The forecast warning the dashboard opens with: the soonest month an
 * account of the active space falls short in, from the stored alerts, so the
 * dashboard never projects the forecast. Only a strong alert that is open,
 * not snoozed, and in a month the user's plan shows counts (the month in
 * progress and the twelve after it with Pro, the next one without).
 *
 * Snoozing ("remind me in March") hides the card until the first day of the
 * month before the problem one. It covers the account's problem from that
 * month on, so the months after it, usually short for the same reason, do not
 * take the card's place the moment it is put away.
 */
class ForecastDashboardAlert
{
    /**
     * @return array{id: string, accountId: string, accountName: string, currencyCode: string, month: string, lowestBalance: int|null, lowestOn: string|null, causes: list<array{name: string, amount: int}>, remindOn: string|null}|null
     */
    public function for(User $user): ?array
    {
        $today = $user->localToday();
        $alert = $this->visible($user, $today)
            ->with('account:id,name,currency_code')
            ->orderBy('month')
            ->orderBy('first_seen_at')
            ->first();

        if ($alert === null) {
            return null;
        }

        return [
            'id' => $alert->id,
            'accountId' => $alert->account_id,
            'accountName' => $alert->account->name,
            'currencyCode' => $alert->account->currency_code,
            'month' => $alert->month->format('Y-m'),
            'lowestBalance' => $alert->lowest_balance,
            'lowestOn' => $alert->lowest_on?->toDateString(),
            'causes' => $alert->causes ?? [],
            'remindOn' => self::remindOn($alert, $today)?->toDateString(),
        ];
    }

    /**
     * Put the alert's account away until the first day of the month before
     * the alert's, from the alert's month on.
     *
     * @return bool False when that day has already come: there is nothing to put off.
     */
    public function snooze(ForecastAlert $alert, User $user): bool
    {
        $remindOn = self::remindOn($alert, $user->localToday());

        if ($remindOn === null) {
            return false;
        }

        ForecastAlert::query()
            ->where('user_id', $user->id)
            ->where('account_id', $alert->account_id)
            ->whereNull('resolved_at')
            ->where('month', '>=', $alert->month->toDateString())
            ->where(fn (Builder $query) => $query->whereNull('snoozed_until')->orWhere('snoozed_until', '<', $remindOn->toDateString()))
            ->update(['snoozed_until' => $remindOn->toDateString()]);

        return true;
    }

    /**
     * The first day of the month before the alert's, or null when that day
     * has already come: there is nothing to put off then.
     */
    private static function remindOn(ForecastAlert $alert, Carbon $today): ?Carbon
    {
        $remindOn = $alert->month->copy()->startOfMonth()->subMonthNoOverflow();

        return $remindOn->gt($today) ? $remindOn : null;
    }

    /**
     * @return Builder<ForecastAlert>
     */
    private function visible(User $user, Carbon $today): Builder
    {
        $firstMonth = $today->copy()->startOfMonth();
        $lastMonth = $firstMonth->copy()->addMonthsNoOverflow(ForecastAlertService::monthsFor($user) - 1);

        return ForecastAlert::query()
            ->where('user_id', $user->id)
            ->forSpace($user->activeSpace()->id)
            ->where('level', ForecastAlertLevel::Strong)
            ->whereNull('resolved_at')
            ->whereBetween('month', [$firstMonth->toDateString(), $lastMonth->toDateString()])
            ->where(fn (Builder $query) => $query->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', $today->toDateString()))
            ->whereHas('account', fn (Builder $query) => $query->notArchived());
    }
}
