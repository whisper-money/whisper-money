<?php

namespace App\Services\SavingsGoals;

use App\Enums\SavingsGoalKind;
use App\Enums\SavingsGoalMonthStatus;
use App\Models\SavingsGoal;
use App\Models\Space;
use App\Models\User;
use App\Services\SpaceResolver;
use Illuminate\Database\Eloquent\Collection;

/**
 * The savings goal row the savings goal MCP tools return and
 * `/api/v1/savings-goals` documents, so both read a goal the same way.
 * Amounts are in minor units.
 *
 * A one-off goal carries `progress` towards its total; a monthly goal carries
 * `monthly`: the month in progress and every month since it was created, each
 * judged against its own target.
 */
class SavingsGoalPresenter
{
    public function __construct(private MonthlySavingsGoalStats $monthlyStats) {}

    /**
     * Every goal of $user in the space, one-off ones first, read in one
     * query. As in the app, another member's goals are left out.
     *
     * @return list<array<string, mixed>>
     */
    public function all(User $user, Space $space): array
    {
        return array_values($this->many(app(SpaceResolver::class)->ownedInSpace(SavingsGoal::query(), $user, $space)->listed()->get()));
    }

    /**
     * Goals already loaded with their labels, keyed by id, one-off ones first:
     * the stats of all of them are read in one batch rather than goal by goal.
     *
     * @param  Collection<int, SavingsGoal>  $goals
     * @return array<string, array<string, mixed>>
     */
    public function many(Collection $goals): array
    {
        [$monthly, $oneOff] = $goals->partition(fn (SavingsGoal $goal): bool => $goal->isMonthly());
        $rows = [];

        foreach ([...SavingsGoal::withStats($oneOff), ...$this->monthlyStats->present($monthly)] as $row) {
            $rows[$row['id']] = $this->row($row);
        }

        return $rows;
    }

    /**
     * One goal, with the stats of that goal alone.
     *
     * @return array<string, mixed>
     */
    public function one(SavingsGoal $goal): array
    {
        $row = $goal->isMonthly()
            ? [...$goal->toArray(), 'monthly' => $this->monthlyStats->forGoal($goal)]
            : SavingsGoal::withStats($goal->newCollection([$goal]))[0];

        return $this->row($row);
    }

    /**
     * @param  array<string, mixed>  $row  a goal's attributes plus its `stats` or `monthly`
     * @return array<string, mixed>
     */
    private function row(array $row): array
    {
        $common = [
            'id' => $row['id'],
            'name' => $row['name'],
            'kind' => $row['kind'],
            // Tag a transaction with this label to count it towards the goal.
            'label_id' => $row['label_id'],
            'archived' => $row['archived_at'] !== null,
        ];

        if ($row['kind'] !== SavingsGoalKind::Monthly->value) {
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
            'monthly' => $this->monthlyBlock($row['monthly']),
        ];
    }

    /**
     * @param  array<string, mixed>  $monthly
     * @return array<string, mixed>
     */
    private function monthlyBlock(array $monthly): array
    {
        return [
            'current' => $this->currentMonth($monthly['current']),
            'history' => array_map(self::month(...), $monthly['history']),
            'months_met' => $monthly['months_met'],
            'months_closed' => $monthly['months_closed'],
            'cumulative_difference' => $monthly['cumulative_difference'],
            'streak' => $monthly['streak'],
            'best_streak' => $monthly['best_streak'],
        ];
    }

    /**
     * A goal's month in progress as the API and the MCP give it, from the
     * `current` entry of {@see MonthlySavingsGoalStats}: the month's figures,
     * what is left to save and the days left.
     *
     * @param  array<string, mixed>|null  $current
     * @return array{month: string, target: int, saved: int, difference: int, status: SavingsGoalMonthStatus, income_base: int|null, remaining: int, days_left: int}|null
     */
    public function currentMonth(?array $current): ?array
    {
        return $current === null ? null : [
            ...self::month($current),
            'remaining' => $current['remaining'],
            'days_left' => $current['days_left'],
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{month: string, target: int, saved: int, difference: int, status: SavingsGoalMonthStatus, income_base: int|null}
     */
    private static function month(array $entry): array
    {
        return [
            'month' => $entry['month'],
            'target' => $entry['target'],
            'saved' => $entry['saved'],
            'difference' => $entry['difference'],
            'status' => $entry['status'],
            'income_base' => $entry['income_base'],
        ];
    }
}
