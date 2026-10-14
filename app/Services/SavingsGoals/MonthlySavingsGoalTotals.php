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
        return $this->forGoals($this->stats->presentForUser($user), $month, $historyMonths);
    }

    /**
     * How the goals did in $month — met of judged — in the shape the Planning
     * header and the dashboard card show it. The same calculation as the
     * cashflow card and the monthly summary, so every surface agrees after an
     * archive. Null when no goal was judged that month.
     *
     * @param  list<array<string, mixed>>  $goals  every monthly goal of the user, archived ones included, as presented
     * @return array{month: string, met: int, total: int}|null
     */
    public function verdicts(array $goals, Carbon $month): ?array
    {
        $totals = $this->forGoals($goals, $month, historyMonths: 1);

        return $totals === null || $totals['total'] === 0
            ? null
            : ['month' => $totals['month'], 'met' => $totals['met'], 'total' => $totals['total']];
    }

    /**
     * The same as forMonth(), from goals already presented, so a page that
     * has them spends no query.
     *
     * @param  list<array<string, mixed>>  $goals
     * @return array<string, mixed>|null
     */
    private function forGoals(array $goals, Carbon $month, int $historyMonths): ?array
    {
        $from = $month->copy()->startOfMonth()->subMonthsNoOverflow($historyMonths - 1);
        $byMonth = $this->byMonth($goals, $from->format('Y-m'), $month->format('Y-m'));
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
     * @param  list<array<string, mixed>>  $goals
     * @return array<string, array<string, mixed>>
     */
    private function byMonth(array $goals, string $from, string $to): array
    {
        $months = [];

        foreach ($goals as $goal) {
            $run = 0;

            foreach ($goal['monthly']['history'] as $entry) {
                // Partial and archived months have no target to add up.
                if ($entry['status'] === SavingsGoalMonthStatus::Partial || $entry['status'] === SavingsGoalMonthStatus::Archived) {
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
