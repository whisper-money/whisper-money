<?php

namespace App\Services\Forecast;

use App\Enums\ForecastAlertLevel;
use App\Mail\ForecastAlertEmail;
use App\Models\ForecastAlert;
use App\Models\User;
use App\Notifications\ForecastAlertRaised;
use App\Services\Forecast\Data\ForecastAlertFinding;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells the user about strong forecast alerts they have not been told about:
 * one line in the bell and, when they want it and can receive it, one email.
 * Soft alerts are only shown in the app.
 *
 * Alerts found in the same run go out together, about the earliest month, so
 * a run that finds three tight months sends one email, not three. Each alert
 * is claimed (`notified_at`) before anything goes out, so overlapping runs
 * send it once, and released if sending fails, or later if the queued email
 * gives up, so the next run tries again. The bell row carries the alerts it
 * was rung for, so a retry after a failed email does not ring twice.
 *
 * Neither carries an amount or an account's name: an inbox, or a bell seen
 * over a shoulder, is not the app.
 */
class ForecastAlertNotifier
{
    /**
     * @param  array<string, ForecastAlertFinding>  $findings  This run's findings, by key: they say why each month is short.
     */
    public function notifyNew(User $user, array $findings): void
    {
        $claimed = $this->pending($user)->filter(fn (ForecastAlert $alert): bool => self::claim($alert->id));

        if ($claimed->isEmpty()) {
            return;
        }

        try {
            $this->send($user, $claimed, $findings);
        } catch (Throwable $exception) {
            self::release($claimed->modelKeys());

            throw $exception;
        }
    }

    /**
     * Give claims back so the next run tries again.
     *
     * @param  list<string>  $alertIds
     */
    public static function release(array $alertIds): void
    {
        ForecastAlert::query()->whereKey($alertIds)->update(['notified_at' => null]);
    }

    /**
     * @return Collection<int, ForecastAlert>
     */
    private function pending(User $user): Collection
    {
        return ForecastAlert::query()
            ->where('user_id', $user->id)
            ->where('level', ForecastAlertLevel::Strong)
            ->whereNull('resolved_at')
            ->whereNull('notified_at')
            ->where('month', '>=', $user->localToday()->startOfMonth()->toDateString())
            ->orderBy('month')
            ->get();
    }

    /**
     * @param  Collection<int, ForecastAlert>  $alerts
     * @param  array<string, ForecastAlertFinding>  $findings
     */
    private function send(User $user, Collection $alerts, array $findings): void
    {
        /** @var ForecastAlert $first */
        $first = $alerts->first();
        $month = $first->month->format('Y-m');
        $signature = self::signature($alerts);

        if (! $this->alreadyRung($user, $signature)) {
            $user->notify(new ForecastAlertRaised($month, $signature, $alerts->modelKeys()));
        }

        if (! $user->canReceiveEmails() || ! $user->wantsForecastAlertEmail()) {
            return;
        }

        Mail::to($user)->send(new ForecastAlertEmail(
            $month,
            (int) $user->localToday()->startOfMonth()->diffInMonths($first->month->copy()->startOfMonth()),
            $findings[$first->key()]->belowMinimum ?? false,
            $alerts->modelKeys(),
        ));
    }

    private function alreadyRung(User $user, string $signature): bool
    {
        return $user->notifications()
            ->where('type', ForecastAlertRaised::class)
            ->where('data->signature', $signature)
            ->exists();
    }

    /**
     * What the alerts were when they were claimed: an alert that resolves and
     * comes back is seen again, and is rung for again.
     *
     * @param  Collection<int, ForecastAlert>  $alerts
     */
    private static function signature(Collection $alerts): string
    {
        return sha1($alerts
            ->map(fn (ForecastAlert $alert): string => $alert->id.'@'.$alert->first_seen_at->toIso8601String())
            ->sort()
            ->implode(','));
    }

    /**
     * Take the alert, atomically, so overlapping runs tell about it once.
     */
    private static function claim(string $alertId): bool
    {
        return ForecastAlert::query()
            ->whereKey($alertId)
            ->whereNull('notified_at')
            ->update(['notified_at' => now()]) === 1;
    }
}
