<?php

namespace App\Services\SavingsGoals;

use App\Enums\SavingsGoalMonthStatus;
use App\Models\User;
use Carbon\Carbon;

/**
 * Every monthly goal of a user added up month by month: what the cashflow card
 * and the monthly summary read. A goal counts in the months it had, archived
 * or not, each judged against that month's own target. A partial month adds
 * nothing: it has no target and no verdict.
 */
class MonthlySavingsGoalTotals
{
    public function __construct(private MonthlySavingsGoalStats $stats) {}

    /**
     * The given month and the ones before it, oldest first. A month no goal
     * was judged in still comes back, empty, with the history before it; null
     * only when no goal had any of those months.
     *
     * @return array{month: string, saved: int, target: int, difference: int, met: int, total: int, target_pending: bool, status: SavingsGoalMonthStatus|null, goals: list<array<string, mixed>>, history: list<array<string, mixed>>}|null
     */
    public function forMonth(User $user, Carbon $month, int $historyMonths = 6): ?array
    {
        $from = $month->copy()->startOfMonth()->subMonthsNoOverflow($historyMonths - 1);
        $byMonth = $this->byMonth($user, $from->format('Y-m'), $month->format('Y-m'));
        $key = $month->format('Y-m');

        if ($byMonth === []) {
            return null;
        }

        $history = array_values(array_map(fn (array $entry): array => array_diff_key($entry, ['goals' => true]), $byMonth));

        return [...($byMonth[$key] ?? self::summarize([], $key)), 'history' => $history];
    }

    /**
     * The months from $from to $to (YYYY-MM, inclusive), keyed and sorted by
     * month. Each goal's streak still runs through its whole history, so a
     * month carries the streak the goal had reached by then.
     *
     * @return array<string, array<string, mixed>>
     */
    private function byMonth(User $user, string $from, string $to): array
    {
        $months = [];

        foreach ($this->stats->presentForUser($user) as $goal) {
            $run = 0;

            foreach ($goal['monthly']['history'] as $entry) {
                if ($entry['status'] === SavingsGoalMonthStatus::Partial) {
                    continue;
                }

                $run = $entry['status'] === SavingsGoalMonthStatus::Met ? $run + 1 : 0;

                if ($entry['month'] < $from || $entry['month'] > $to) {
                    continue;
                }

                $months[$entry['month']][] = [
                    'id' => $goal['id'],
                    'name' => $goal['name'],
                    'saved' => $entry['saved'],
                    'target' => $entry['target'],
                    'difference' => $entry['difference'],
                    'status' => $entry['status'],
                    'is_live_target' => $entry['is_live_target'],
                    'streak' => $run,
                ];
            }
        }

        ksort($months);

        foreach ($months as $month => $goals) {
            $months[$month] = self::summarize($goals, $month);
        }

        return $months;
    }

    /**
     * @param  list<array<string, mixed>>  $goals
     * @return array<string, mixed>
     */
    private static function summarize(array $goals, string $month): array
    {
        $saved = array_sum(array_column($goals, 'saved'));
        $target = array_sum(array_column($goals, 'target'));
        $inProgress = in_array(SavingsGoalMonthStatus::InProgress, array_column($goals, 'status'), true);
        $waiting = array_filter($goals, self::waitingForIncome(...));

        return [
            'month' => $month,
            'saved' => $saved,
            'target' => $target,
            'difference' => $saved - $target,
            // Reached rather than judged: in a month still running it reads as
            // "reached so far", and a closed month is met exactly when reached.
            'met' => count(array_filter($goals, fn (array $goal): bool => ! self::waitingForIncome($goal) && self::reached($goal['saved'], $goal['target']))),
            'total' => count($goals),
            // A share of income that has not come in yet is a target of 0 that
            // is not met: while every goal is in that state the month has no
            // target to show.
            'target_pending' => $goals !== [] && count($waiting) === count($goals),
            'status' => match (true) {
                $goals === [] => null,
                $inProgress => SavingsGoalMonthStatus::InProgress,
                self::reached($saved, $target) => SavingsGoalMonthStatus::Met,
                default => SavingsGoalMonthStatus::Missed,
            },
            'goals' => $goals,
        ];
    }

    /**
     * The stats' rule: a target of nothing is met, whatever was saved.
     */
    private static function reached(int $saved, int $target): bool
    {
        return $target <= 0 || $saved >= $target;
    }

    /**
     * A month still running whose target follows an income that has not
     * arrived yet.
     *
     * @param  array<string, mixed>  $goal
     */
    private static function waitingForIncome(array $goal): bool
    {
        return $goal['status'] === SavingsGoalMonthStatus::InProgress && $goal['is_live_target'] && $goal['target'] <= 0;
    }
}
