<?php

namespace App\Services\SavingsGoals;

use App\Mail\MonthlySavingsGoalReminderEmail;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalPeriod;
use App\Models\User;
use App\Notifications\MonthlySavingsGoalClosed;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * What the daily command tells the user about their monthly goals: a line in
 * the bell for each goal when the month just gone is closed, and one email a
 * month, near its end, listing every goal still short of its target.
 *
 * Each message is claimed on its period before it goes out, so overlapping
 * runs send it once, and released again if it fails — sending it, or later
 * delivering the queued email — so the next run retries it rather than
 * losing it.
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

    /**
     * One bell row for the month just gone. Older months closed after an
     * outage are not announced: they would arrive as a burst of stale news.
     */
    public function announce(SavingsGoal $goal): void
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

        if ($period === null || ! self::claim($period->id, 'closed_notified_at')) {
            return;
        }

        try {
            $stats = $this->stats->forGoal($goal);
            $entry = collect($stats['history'])->firstWhere('month', $month->format('Y-m'));

            $goal->user->notify(new MonthlySavingsGoalClosed($goal, $entry, $stats['streak']));
        } catch (Throwable $exception) {
            self::release([$period->id], 'closed_notified_at');

            throw $exception;
        }
    }

    /**
     * In the last days of the month, one email listing every goal of the user
     * that is still short of its target. A goal already reminded this month is
     * left out, so a run after a partial failure only sends what is missing.
     */
    public function remind(User $user): void
    {
        if (! self::inReminderWindow() || ! $user->canReceiveEmails()) {
            return;
        }

        $behind = $this->goalsBehind($user);
        $claimed = $behind->filter(fn (array $item): bool => self::claim($item['period_id'], 'reminder_notified_at'));

        if ($claimed->isEmpty()) {
            return;
        }

        $periodIds = $claimed->pluck('period_id')->all();

        try {
            Mail::to($user)->send(new MonthlySavingsGoalReminderEmail(
                $user,
                today()->format('Y-m'),
                $claimed->map(fn (array $item): array => ['name' => $item['name'], 'percent' => $item['percent']])->values()->all(),
                (int) today()->diffInDays(today()->endOfMonth()) + 1,
                $periodIds,
            ));
        } catch (Throwable $exception) {
            self::release($periodIds, 'reminder_notified_at');

            throw $exception;
        }
    }

    /**
     * Give a claim back so the next run tries again. Called when sending
     * throws, and by the reminder email when its queued delivery gives up.
     *
     * @param  list<string>  $periodIds
     */
    public static function release(array $periodIds, string $marker): void
    {
        SavingsGoalPeriod::query()->whereKey($periodIds)->update([$marker => null]);
    }

    /**
     * The running goals that want the reminder, are judged this month, and are
     * still short of a target above 0, with how far along each one is.
     *
     * @return Collection<int, array{period_id: string, name: string, percent: int}>
     */
    private function goalsBehind(User $user): Collection
    {
        $goals = $user->savingsGoals()
            ->monthly()
            ->notArchived()
            ->where('notify_on_month_end_reminder', true)
            ->get()
            ->reject(fn (SavingsGoal $goal): bool => $goal->isPartialMonth(today()));

        if ($goals->isEmpty()) {
            return collect();
        }

        $stats = $this->stats->forGoals($goals);
        $periods = SavingsGoalPeriod::query()
            ->whereIn('savings_goal_id', $goals->modelKeys())
            ->where('month', today()->startOfMonth()->toDateString())
            ->whereNull('reminder_notified_at')
            ->get()
            ->keyBy('savings_goal_id');

        return $goals
            ->filter(fn (SavingsGoal $goal): bool => $periods->has($goal->id) && self::isBehind($stats[$goal->id]['current']))
            ->map(fn (SavingsGoal $goal): array => [
                'period_id' => $periods->get($goal->id)->id,
                'name' => $goal->name,
                'percent' => self::percentOf($stats[$goal->id]['current']['saved'], $stats[$goal->id]['current']['target']),
            ])
            ->values()
            ->toBase();
    }

    /**
     * A target of nothing is already met, even when no income came in yet.
     *
     * @param  array<string, mixed>|null  $current
     */
    private static function isBehind(?array $current): bool
    {
        return $current !== null && $current['target'] > 0 && $current['saved'] < $current['target'];
    }

    /**
     * Take $marker on the period, atomically, so overlapping runs send once.
     */
    private static function claim(string $periodId, string $marker): bool
    {
        return SavingsGoalPeriod::query()
            ->whereKey($periodId)
            ->whereNull($marker)
            ->update([$marker => now()]) === 1;
    }

    private static function inReminderWindow(): bool
    {
        return today()->gte(self::reminderWindowStart(today())) && now()->hour >= self::REMINDER_HOUR;
    }

    /**
     * How far along the month is, in whole percent. Rounded down and kept
     * below 100, so a goal a cent short never reads as done.
     */
    private static function percentOf(int $saved, int $target): int
    {
        return min(99, max(0, intdiv($saved * 100, $target)));
    }

    private static function reminderWindowStart(Carbon $month): Carbon
    {
        return $month->copy()->endOfMonth()->startOfDay()->subDays(self::REMINDER_DAYS_LEFT - 1);
    }
}
