<?php

namespace App\Services\SavingsGoals;

use App\Mail\MonthlySavingsGoalReminderEmail;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalPeriod;
use App\Notifications\MonthlySavingsGoalClosed;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * What the daily command tells the user about a monthly goal: a line in the
 * bell when the month just gone is closed, and one email per month when the
 * month is about to end short of its target.
 *
 * Each message is claimed on its period before it goes out, so overlapping
 * runs send it once, and released again if sending fails, so the next run
 * retries it rather than losing it.
 *
 * A partial month — the goal was created in its last days — is left alone,
 * by the same rule the stats use to keep it unjudged: a reminder a day after
 * creating the goal, and a "missed" notice for a stub month, would be noise.
 */
class MonthlySavingsGoalNotifier
{
    /**
     * The reminder goes out once this many days of the month are left, today
     * included.
     */
    private const REMINDER_DAYS_LEFT = 5;

    /**
     * The daily command also runs just after midnight; the reminder waits for
     * the run at breakfast time in Europe rather than landing in the night.
     */
    private const REMINDER_HOUR = 7;

    public function __construct(private MonthlySavingsGoalStats $stats) {}

    public function notify(SavingsGoal $goal): void
    {
        $this->announceLastMonth($goal);
        $this->remindIfBehind($goal);
    }

    /**
     * One bell row for the month just gone. Older months closed after an
     * outage are not announced: they would arrive as a burst of stale news.
     */
    private function announceLastMonth(SavingsGoal $goal): void
    {
        $month = today()->startOfMonth()->subMonth();

        if ($goal->isPartialMonth($month)) {
            return;
        }

        $period = $goal->periods()
            ->where('month', $month->toDateString())
            ->whereNotNull('closed_at')
            ->whereNull('closed_notified_at')
            ->first();

        $this->sendOnce($period, 'closed_notified_at', function () use ($goal, $month): void {
            $stats = $this->stats->forGoal($goal);
            $entry = collect($stats['history'])->firstWhere('month', $month->format('Y-m'));

            $goal->user->notify(new MonthlySavingsGoalClosed($goal, $entry, $stats['streak']));
        });
    }

    /**
     * Email the user in the last days of the month when the goal is still
     * short of its target.
     */
    private function remindIfBehind(SavingsGoal $goal): void
    {
        if (! $this->wantsReminderNow($goal)) {
            return;
        }

        $period = $goal->periods()
            ->where('month', today()->startOfMonth()->toDateString())
            ->whereNull('reminder_notified_at')
            ->first();

        if ($period === null) {
            return;
        }

        $current = $this->stats->forGoal($goal)['current'];

        // A target of nothing is already met, even when no income came in yet.
        if ($current === null || $current['target'] <= 0 || $current['saved'] >= $current['target']) {
            return;
        }

        $this->sendOnce($period, 'reminder_notified_at', fn () => Mail::to($goal->user)->send(new MonthlySavingsGoalReminderEmail(
            $goal->user,
            $goal,
            $current['month'],
            self::percentOf($current['saved'], $current['target']),
            $current['days_left'],
        )));
    }

    private function wantsReminderNow(SavingsGoal $goal): bool
    {
        return today()->gte(self::reminderWindowStart(today()))
            && now()->hour >= self::REMINDER_HOUR
            && $goal->notify_on_month_end_reminder
            && ! $goal->isPartialMonth(today())
            && $goal->user->canReceiveEmails();
    }

    /**
     * How far along the month is, in whole percent. Rounded down and kept
     * below 100, so a goal a cent short never reads as done.
     */
    private static function percentOf(int $saved, int $target): int
    {
        return min(99, max(0, intdiv($saved * 100, $target)));
    }

    /**
     * Claim $marker on the period, send, and give the claim back if sending
     * throws.
     */
    private function sendOnce(?SavingsGoalPeriod $period, string $marker, callable $send): void
    {
        if ($period === null) {
            return;
        }

        $claimed = SavingsGoalPeriod::query()
            ->whereKey($period->id)
            ->whereNull($marker)
            ->update([$marker => now()]);

        if ($claimed !== 1) {
            return;
        }

        try {
            $send();
        } catch (Throwable $exception) {
            SavingsGoalPeriod::query()->whereKey($period->id)->update([$marker => null]);

            throw $exception;
        }
    }

    private static function reminderWindowStart(Carbon $month): Carbon
    {
        return $month->copy()->endOfMonth()->startOfDay()->subDays(self::REMINDER_DAYS_LEFT - 1);
    }
}
