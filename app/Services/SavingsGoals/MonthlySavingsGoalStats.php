<?php

namespace App\Services\SavingsGoals;

use App\Models\SavingsGoal;
use App\Models\SavingsGoalPeriod;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Where a monthly savings goal stands, month by month.
 *
 * What was saved is always summed live from the goal's tagged transactions, so
 * a transaction that syncs after its month closed still moves that month. Only
 * the target is frozen, on the period. A month is judged once it is over: met
 * when saved reaches the target, with no carry-over between months.
 */
class MonthlySavingsGoalStats
{
    private const STATUS_MET = 'met';

    private const STATUS_MISSED = 'missed';

    private const STATUS_IN_PROGRESS = 'in_progress';

    public function __construct(private MonthlyTargetResolver $targets) {}

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
                $goal->id => $this->summarize($this->history($goal, $savedByLabel[$goal->label_id] ?? [])),
            ])
            ->all();
    }

    /**
     * One row per period, oldest first.
     *
     * @param  array<string, int>  $savedByMonth
     * @return list<array<string, mixed>>
     */
    private function history(SavingsGoal $goal, array $savedByMonth): array
    {
        $currentMonth = today()->startOfMonth();

        return $goal->periods
            ->map(function (SavingsGoalPeriod $period) use ($goal, $savedByMonth, $currentMonth): array {
                $saved = $savedByMonth[$period->monthKey()] ?? 0;
                $target = $this->targets->targetFor($period, $goal->user);

                return [
                    'month' => $period->monthKey(),
                    'target_type' => $period->target_type->value,
                    'target_amount' => $period->target_amount,
                    'target_rate' => $period->target_rate,
                    'income_base' => $this->incomeBase($period, $target),
                    'is_live_target' => $period->resolved_target_amount === null,
                    'target' => $target,
                    'saved' => $saved,
                    'difference' => $saved - $target,
                    'status' => self::status($period->month, $currentMonth, $saved, $target),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The income a share-of-income target was worked out from, for the "20% of
     * 2,400" line. Read back from the target rather than stored twice.
     */
    private function incomeBase(SavingsGoalPeriod $period, int $target): ?int
    {
        if ($period->target_rate === null || $period->target_rate <= 0) {
            return null;
        }

        return (int) round($target * 100 / $period->target_rate);
    }

    private static function status(Carbon $month, Carbon $currentMonth, int $saved, int $target): string
    {
        if ($month->gte($currentMonth)) {
            return self::STATUS_IN_PROGRESS;
        }

        return $saved >= $target ? self::STATUS_MET : self::STATUS_MISSED;
    }

    /**
     * @param  list<array<string, mixed>>  $history
     * @return array<string, mixed>
     */
    private function summarize(array $history): array
    {
        $closed = array_values(array_filter($history, fn (array $month): bool => $month['status'] !== self::STATUS_IN_PROGRESS));
        $current = collect($history)->firstWhere('month', today()->format('Y-m'));

        return [
            'current' => $current === null ? null : [
                ...$current,
                'remaining' => max(0, $current['target'] - $current['saved']),
                'days_left' => (int) today()->diffInDays(today()->endOfMonth()) + 1,
            ],
            'history' => $history,
            'months_met' => count(array_filter($closed, fn (array $month): bool => $month['status'] === self::STATUS_MET)),
            'months_closed' => count($closed),
            'cumulative_difference' => array_sum(array_column($closed, 'difference')),
            'cumulative_saved' => array_sum(array_column($closed, 'saved')),
            'cumulative_target' => array_sum(array_column($closed, 'target')),
            ...self::streaks($closed),
        ];
    }

    /**
     * The run of met months up to the last closed one, and the longest run.
     *
     * @param  list<array<string, mixed>>  $closed
     * @return array{streak: int, best_streak: int}
     */
    private static function streaks(array $closed): array
    {
        $run = 0;
        $best = 0;

        foreach ($closed as $month) {
            $run = $month['status'] === self::STATUS_MET ? $run + 1 : 0;
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

        $rows = SavingsGoal::taggedContributions($labelIds)
            ->where('transactions.transaction_date', '>=', $firstMonth->toDateString())
            ->groupBy('label_transaction.label_id', 'transactions.transaction_date')
            ->selectRaw('label_transaction.label_id as label_id, transactions.transaction_date as day, SUM('.SavingsGoal::CONTRIBUTION_AMOUNT_SQL.') as total')
            ->toBase()
            ->get();

        $saved = [];

        foreach ($rows as $row) {
            $month = Carbon::parse($row->day)->format('Y-m');
            $saved[$row->label_id][$month] = ($saved[$row->label_id][$month] ?? 0) + (int) $row->total;
        }

        return $saved;
    }
}
