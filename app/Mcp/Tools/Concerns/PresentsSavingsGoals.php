<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\SavingsGoal;
use App\Models\User;
use App\Services\SavingsGoals\MonthlySavingsGoalStats;

/**
 * The savings goal shape every savings goal tool returns, so list, create and
 * update hand the agent the same row. Amounts are in minor units.
 *
 * A one-off goal carries `progress` towards its total; a monthly goal carries
 * `monthly`: the month in progress and every month since it was created, each
 * judged against its own target.
 */
trait PresentsSavingsGoals
{
    /**
     * @return list<array<string, mixed>>
     */
    protected function presentSavingsGoals(User $user): array
    {
        return array_map(
            fn (array $row): array => $this->presentSavingsGoalRow($row),
            [...SavingsGoal::withStatsForUser($user), ...app(MonthlySavingsGoalStats::class)->presentForUser($user)],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentSavingsGoal(SavingsGoal $goal): array
    {
        $row = $goal->isMonthly()
            ? [...$goal->toArray(), 'monthly' => app(MonthlySavingsGoalStats::class)->forGoal($goal)]
            : collect(SavingsGoal::withStatsForUser($goal->user))->firstWhere('id', $goal->id);

        return $this->presentSavingsGoalRow($row);
    }

    /**
     * @param  array<string, mixed>  $row  a goal's attributes plus its `stats` or `monthly`
     * @return array<string, mixed>
     */
    private function presentSavingsGoalRow(array $row): array
    {
        $common = [
            'id' => $row['id'],
            'name' => $row['name'],
            'kind' => $row['kind'],
            'archived' => $row['archived_at'] !== null,
        ];

        if ($row['kind'] !== 'monthly') {
            return [
                ...$common,
                'target_amount' => $row['target_amount'],
                'initial_amount' => $row['initial_amount'],
                'target_date' => $row['stats']['target_date'],
                'progress' => $row['stats'],
            ];
        }

        return [
            ...$common,
            'monthly_target_type' => $row['monthly_target_type'],
            'monthly_target_amount' => $row['monthly_target_amount'],
            'monthly_target_rate' => $row['monthly_target_rate'],
            'notify_on_month_end_reminder' => $row['notify_on_month_end_reminder'],
            'monthly' => $this->presentMonthlyStats($row['monthly']),
        ];
    }

    /**
     * @param  array<string, mixed>  $monthly
     * @return array<string, mixed>
     */
    private function presentMonthlyStats(array $monthly): array
    {
        $month = fn (array $entry): array => [
            'month' => $entry['month'],
            'target' => $entry['target'],
            'saved' => $entry['saved'],
            'difference' => $entry['difference'],
            'status' => $entry['status'],
            'income_base' => $entry['income_base'],
        ];

        return [
            'current' => $monthly['current'] === null ? null : [
                ...$month($monthly['current']),
                'remaining' => $monthly['current']['remaining'],
                'days_left' => $monthly['current']['days_left'],
            ],
            'history' => array_map($month, $monthly['history']),
            'months_met' => $monthly['months_met'],
            'months_closed' => $monthly['months_closed'],
            'cumulative_difference' => $monthly['cumulative_difference'],
            'streak' => $monthly['streak'],
            'best_streak' => $monthly['best_streak'],
        ];
    }
}
