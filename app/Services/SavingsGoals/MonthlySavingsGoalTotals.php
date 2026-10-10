<?php

namespace App\Services\SavingsGoals;

use App\Models\User;
use Carbon\Carbon;

/**
 * Every monthly goal of a user added up month by month: what the cashflow card
 * and the monthly summary read. A goal counts in the months it had, archived
 * or not, each judged against that month's own target.
 */
class MonthlySavingsGoalTotals
{
    public function __construct(private MonthlySavingsGoalStats $stats) {}

    /**
     * The given month and the ones before it, oldest first. Null when no goal
     * had that month.
     *
     * @return array{month: string, saved: int, target: int, difference: int, met: int, total: int, status: string, goals: list<array<string, mixed>>, history: list<array<string, mixed>>}|null
     */
    public function forMonth(User $user, Carbon $month, int $historyMonths = 6): ?array
    {
        $byMonth = $this->byMonth($user);
        $key = $month->format('Y-m');

        if (! isset($byMonth[$key])) {
            return null;
        }

        $history = [];

        for ($cursor = $month->copy()->startOfMonth()->subMonthsNoOverflow($historyMonths - 1); $cursor->lte($month); $cursor->addMonthNoOverflow()) {
            $entry = $byMonth[$cursor->format('Y-m')] ?? null;

            if ($entry !== null) {
                $history[] = array_diff_key($entry, ['goals' => true]);
            }
        }

        return [...$byMonth[$key], 'history' => $history];
    }

    /**
     * @return array<string, array<string, mixed>> keyed by YYYY-MM
     */
    private function byMonth(User $user): array
    {
        $months = [];

        foreach ($this->stats->presentForUser($user) as $goal) {
            $run = 0;

            foreach ($goal['monthly']['history'] as $entry) {
                $run = $entry['status'] === 'met' ? $run + 1 : 0;
                $months[$entry['month']][] = [
                    'id' => $goal['id'],
                    'name' => $goal['name'],
                    'saved' => $entry['saved'],
                    'target' => $entry['target'],
                    'difference' => $entry['difference'],
                    'status' => $entry['status'],
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
        $inProgress = in_array('in_progress', array_column($goals, 'status'), true);

        return [
            'month' => $month,
            'saved' => $saved,
            'target' => $target,
            'difference' => $saved - $target,
            // Reached rather than judged: in a month still running it reads as
            // "reached so far", and a closed month is met exactly when reached.
            'met' => count(array_filter($goals, fn (array $goal): bool => $goal['saved'] >= $goal['target'])),
            'total' => count($goals),
            'status' => $inProgress ? 'in_progress' : ($saved >= $target ? 'met' : 'missed'),
            'goals' => $goals,
        ];
    }
}
