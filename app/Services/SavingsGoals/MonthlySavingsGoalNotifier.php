<?php

namespace App\Services\SavingsGoals;

use App\Mail\MonthlySavingsGoalReminderEmail;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalPeriod;
use App\Notifications\MonthlySavingsGoalClosed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * What the daily command tells the user about a monthly goal: a line in the
 * bell for every month that just closed, and one email per month when the
 * month is about to end short of its target.
 */
class MonthlySavingsGoalNotifier
{
    /**
     * How many days before the month ends the reminder goes out.
     */
    private const REMINDER_DAYS_BEFORE_MONTH_END = 5;

    public function __construct(private MonthlySavingsGoalStats $stats) {}

    /**
     * One bell row per month the command closed. Only the month just gone is
     * announced: months filled in after an outage would arrive as a burst of
     * stale news.
     *
     * @param  Collection<int, SavingsGoalPeriod>  $closed
     */
    public function monthsClosed(SavingsGoal $goal, Collection $closed): void
    {
        $previousMonth = today()->startOfMonth()->subMonth()->format('Y-m');

        if (! $closed->contains(fn (SavingsGoalPeriod $period): bool => $period->monthKey() === $previousMonth)) {
            return;
        }

        $stats = $this->stats->forGoal($goal);
        $month = collect($stats['history'])->firstWhere('month', $previousMonth);

        $goal->user->notify(new MonthlySavingsGoalClosed($goal, $month, $stats['streak']));
    }

    /**
     * Email the user once in the last days of the month when the goal is still
     * short of its target. The period is claimed before sending, so overlapping
     * runs send it once.
     */
    public function remindIfBehind(SavingsGoal $goal): void
    {
        if (! $this->isReminderWindow() || ! $goal->notify_on_month_end_reminder || ! $goal->user->canReceiveEmails()) {
            return;
        }

        $current = $this->stats->forGoal($goal)['current'];

        if ($current === null || $current['saved'] >= $current['target'] || ! $this->claimReminder($goal)) {
            return;
        }

        Mail::to($goal->user)->send(new MonthlySavingsGoalReminderEmail(
            $goal->user,
            $goal,
            $current['month'],
            $current['saved'],
            $current['target'],
            $current['days_left'],
        ));
    }

    private function isReminderWindow(): bool
    {
        return today()->gte(today()->endOfMonth()->startOfDay()->subDays(self::REMINDER_DAYS_BEFORE_MONTH_END));
    }

    private function claimReminder(SavingsGoal $goal): bool
    {
        return SavingsGoalPeriod::query()
            ->where('savings_goal_id', $goal->id)
            ->where('month', today()->startOfMonth()->toDateString())
            ->whereNull('reminder_notified_at')
            ->update(['reminder_notified_at' => now()]) === 1;
    }
}
