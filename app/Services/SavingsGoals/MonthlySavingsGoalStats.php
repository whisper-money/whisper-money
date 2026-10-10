<?php

namespace App\Services\SavingsGoals;

use App\Enums\SavingsGoalMonthStatus;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalPeriod;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Where a monthly savings goal stands, month by month.
 *
 * What was saved is always summed live from the goal's tagged transactions, so
 * a transaction that syncs after its month closed still moves that month. Only
 * the target is frozen, on the period. A month is judged once it is over: met
 * when saved reaches the target, with no carry-over between months. A partial
 * month — the goal started in its last days, or was archived during it — is
 * shown but never judged.
 */
class MonthlySavingsGoalStats
{
    public function __construct(
        private MonthlyTargetResolver $targets,
        private SavingsGoalPeriodService $periods,
    ) {}

    /**
     * The stats of a single goal.
     *
     * @api The entry point for one goal's page, its notices and the MCP tools.
     *
     * @return array<string, mixed>
     */
    public function forGoal(SavingsGoal $goal): array
    {
        return $this->forGoals($goal->newCollection([$goal]))[$goal->id];
    }

    /**
     * Monthly goals of one user, archived ones included, as the pages render
     * them: the goal's own fields plus a `monthly` block with its stats, oldest
     * first. Shaping the page payload here keeps the one batched stats query
     * next to the goals it was run for. The goals come in already loaded with
     * their labels, so a page that lists both kinds reads the goals once.
     *
     * @param  Collection<int, SavingsGoal>  $goals
     * @return list<array<string, mixed>>
     */
    public function present(Collection $goals): array
    {
        $goals = $goals->sortBy('created_at')->values();
        $stats = $this->forGoals($goals);

        return $goals
            ->map(fn (SavingsGoal $goal): array => [...$goal->toArray(), 'monthly' => $stats[$goal->id]])
            ->all();
    }

    /**
     * Stats for several goals of one user at once: a single query for every
     * goal's contributions, however many months they span.
     *
     * @param  Collection<int, SavingsGoal>  $goals
     * @return array<string, array<string, mixed>> keyed by goal id
     */
    public function forGoals(Collection $goals): array
    {
        $goals->loadMissing(['periods' => fn ($query) => $query->orderBy('month'), 'user']);

        $savedByLabel = $this->savedByMonth($goals);

        return $goals
            ->mapWithKeys(fn (SavingsGoal $goal): array => [
                $goal->id => $this->summarize($goal, $this->history($goal, $this->periodsOf($goal), $savedByLabel[$goal->label_id] ?? [])),
            ])
            ->all();
    }

    /**
     * The goal's periods, plus — for a running goal the daily command has not
     * caught up with yet, say on the 1st before it ran — the missing months as
     * unsaved periods. Reading never writes; the month still shows.
     *
     * @return SupportCollection<int, SavingsGoalPeriod>
     */
    private function periodsOf(SavingsGoal $goal): SupportCollection
    {
        if ($goal->isArchived()) {
            return $goal->periods->toBase();
        }

        return $goal->periods->toBase()
            ->concat($this->periods->pendingPeriods($goal))
            ->sortBy(fn (SavingsGoalPeriod $period): string => $period->monthKey())
            ->values();
    }

    /**
     * One row per period, oldest first.
     *
     * @param  SupportCollection<int, SavingsGoalPeriod>  $periods
     * @param  array<string, int>  $savedByMonth
     * @return list<array<string, mixed>>
     */
    private function history(SavingsGoal $goal, SupportCollection $periods, array $savedByMonth): array
    {
        return $periods
            ->map(function (SavingsGoalPeriod $period) use ($goal, $savedByMonth): array {
                $saved = $savedByMonth[$period->monthKey()] ?? 0;
                ['resolved_target_amount' => $target, 'income_base' => $incomeBase] = $this->targets->current($period, $goal);

                return [
                    'month' => $period->monthKey(),
                    'target_type' => $period->target_type->value,
                    'target_amount' => $period->target_amount,
                    'target_rate' => $period->target_rate,
                    'income_base' => $incomeBase,
                    'is_live_target' => $period->resolved_target_amount === null,
                    'target' => $target,
                    'saved' => $saved,
                    'difference' => $saved - $target,
                    'status' => self::status($goal, $period->month, $saved, $target),
                ];
            })
            ->values()
            ->all();
    }

    private static function status(SavingsGoal $goal, Carbon $month, int $saved, int $target): SavingsGoalMonthStatus
    {
        if ($goal->startedLateIn($month)) {
            return SavingsGoalMonthStatus::Partial;
        }

        if ($goal->wasArchivedIn($month)) {
            return SavingsGoalMonthStatus::Archived;
        }

        if ($month->gte(today()->startOfMonth())) {
            return SavingsGoalMonthStatus::InProgress;
        }

        // A target of nothing is met by definition, even in a month whose
        // withdrawals left the saved amount below zero.
        return $target <= 0 || $saved >= $target ? SavingsGoalMonthStatus::Met : SavingsGoalMonthStatus::Missed;
    }

    /**
     * @param  list<array<string, mixed>>  $history
     * @return array<string, mixed>
     */
    private function summarize(SavingsGoal $goal, array $history): array
    {
        $judged = array_values(array_filter($history, fn (array $month): bool => $month['status']->isJudged()));
        // An archived goal has no month in progress: what it saved this month
        // stays in its history, without a countdown to a target it dropped.
        $current = $goal->isArchived() ? null : collect($history)->firstWhere('month', today()->format('Y-m'));

        return [
            'current' => $current === null ? null : [
                ...$current,
                'remaining' => max(0, $current['target'] - $current['saved']),
                'days_left' => (int) today()->diffInDays(today()->endOfMonth()) + 1,
            ],
            'history' => $history,
            'months_met' => count(array_filter($judged, fn (array $month): bool => $month['status'] === SavingsGoalMonthStatus::Met)),
            'months_closed' => count($judged),
            'cumulative_difference' => array_sum(array_column($judged, 'difference')),
            'cumulative_saved' => array_sum(array_column($judged, 'saved')),
            'cumulative_target' => array_sum(array_column($judged, 'target')),
            ...self::streaks($judged),
        ];
    }

    /**
     * The run of met months up to the last judged one, and the longest run.
     * Partial months sit outside it: they neither extend nor break a streak.
     *
     * @param  list<array<string, mixed>>  $judged
     * @return array{streak: int, best_streak: int}
     */
    private static function streaks(array $judged): array
    {
        $run = 0;
        $best = 0;

        foreach ($judged as $month) {
            $run = $month['status'] === SavingsGoalMonthStatus::Met ? $run + 1 : 0;
            $best = max($best, $run);
        }

        return ['streak' => $run, 'best_streak' => $best];
    }

    /**
     * Contributions per label and calendar month. Grouped by day in SQL and
     * folded into months here, which keeps the query portable.
     *
     * @param  Collection<int, SavingsGoal>  $goals
     * @return array<string, array<string, int>> label id => [Y-m => cents]
     */
    private function savedByMonth(Collection $goals): array
    {
        $labelIds = $goals->pluck('label_id')->filter()->unique()->values();
        $firstMonth = $goals->flatMap->periods->min('month');

        if ($labelIds->isEmpty() || $firstMonth === null) {
            return [];
        }

        $rows = SavingsGoal::contributionsByDay($labelIds, $firstMonth);

        $saved = [];

        foreach ($rows as $row) {
            $month = Carbon::parse($row->day)->format('Y-m');
            $saved[$row->label_id][$month] = ($saved[$row->label_id][$month] ?? 0) + (int) $row->total;
        }

        return $saved;
    }
}
