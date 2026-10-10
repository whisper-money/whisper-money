<?php

namespace App\Services\SavingsGoals;

use App\Models\SavingsGoal;
use App\Models\SavingsGoalPeriod;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The month lifecycle of a monthly savings goal: a period opens on the 1st
 * (or the day the goal is created) with a copy of the target in force, and
 * closes once the month is over, freezing that target for good.
 *
 * Every step is safe to repeat. Opening finds the existing row on the
 * (goal, month) unique key, and closing only claims a period nobody closed yet,
 * so overlapping or repeated runs of the daily command change nothing twice.
 */
class SavingsGoalPeriodService
{
    public function __construct(private MonthlyTargetResolver $targets) {}

    public function openPeriod(SavingsGoal $goal, Carbon $month): SavingsGoalPeriod
    {
        $month = $month->copy()->startOfMonth();

        return SavingsGoalPeriod::query()->firstOrCreate(
            ['savings_goal_id' => $goal->id, 'month' => $month->toDateString()],
            $this->targetAttributes($goal, $month),
        );
    }

    /**
     * Carry an edited target onto the month in progress. Months already over
     * keep the target they were held to.
     */
    public function syncCurrentPeriod(SavingsGoal $goal): SavingsGoalPeriod
    {
        $period = $this->openPeriod($goal, today());

        if ($period->closed_at === null) {
            $period->update($this->targetAttributes($goal, $period->month));
        }

        return $period;
    }

    /**
     * Bring a goal up to today: open every month from its last period (or its
     * creation month) through the current one, then close every month that is
     * over. A gap left by a command that did not run is filled rather than
     * skipped, so the history has no holes.
     *
     * @return Collection<int, SavingsGoalPeriod> the periods this call closed, oldest first
     */
    public function advance(SavingsGoal $goal): Collection
    {
        $currentMonth = today()->startOfMonth();
        $lastMonth = $goal->periods()->max('month');
        $cursor = $lastMonth === null
            ? $goal->created_at->copy()->startOfMonth()
            : Carbon::parse($lastMonth)->addMonthNoOverflow()->startOfMonth();

        for (; $cursor->lte($currentMonth); $cursor->addMonthNoOverflow()) {
            $this->openPeriod($goal, $cursor);
        }

        return $goal->periods()
            ->whereNull('closed_at')
            ->where('month', '<', $currentMonth->toDateString())
            ->orderBy('month')
            ->get()
            ->filter(fn (SavingsGoalPeriod $period): bool => $this->close($goal, $period))
            ->values();
    }

    /**
     * Freeze the month's target and mark it closed. A share-of-income target
     * that was still followed live is frozen at the month's full income.
     *
     * @return bool whether this call was the one that closed it
     */
    private function close(SavingsGoal $goal, SavingsGoalPeriod $period): bool
    {
        $target = $this->targets->targetFor($period, $goal->user);

        $claimed = SavingsGoalPeriod::query()
            ->whereKey($period->id)
            ->whereNull('closed_at')
            ->update(['closed_at' => now(), 'resolved_target_amount' => $target, 'updated_at' => now()]);

        if ($claimed === 1) {
            $period->forceFill(['closed_at' => now(), 'resolved_target_amount' => $target]);
        }

        return $claimed === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function targetAttributes(SavingsGoal $goal, Carbon $month): array
    {
        return [
            'target_type' => $goal->monthly_target_type,
            'target_amount' => $goal->monthly_target_amount,
            'target_rate' => $goal->monthly_target_rate,
            'resolved_target_amount' => $this->targets->resolveAtOpen(
                $goal->user,
                $goal->monthly_target_type,
                $goal->monthly_target_amount,
                $goal->monthly_target_rate,
                $month,
            ),
        ];
    }
}
