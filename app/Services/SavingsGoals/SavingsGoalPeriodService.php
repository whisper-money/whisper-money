<?php

namespace App\Services\SavingsGoals;

use App\Enums\MonthlyTargetType;
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

        $existing = $goal->periods()->where('month', $month->toDateString())->first();

        // Checked first rather than left to firstOrCreate: resolving a
        // share-of-income target reads three months of cashflow, which an
        // already-open month does not need.
        return $existing ?? SavingsGoalPeriod::query()->firstOrCreate(
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
        $this->advance($goal);
        $period = $this->openPeriod($goal, today());

        if ($period->closed_at === null) {
            $period->update($this->editedTargetAttributes($goal, $period));
        }

        return $period;
    }

    /**
     * The month in progress after an edit. A share of income edited into
     * another share keeps the income the month opened with and only applies
     * the new rate: re-reading the three-month average now could move the base
     * under a user who only changed the percentage.
     *
     * @return array<string, mixed>
     */
    private function editedTargetAttributes(SavingsGoal $goal, SavingsGoalPeriod $period): array
    {
        $staysShareOfIncome = $period->target_type === MonthlyTargetType::IncomeRate
            && $goal->monthly_target_type === MonthlyTargetType::IncomeRate;

        if (! $staysShareOfIncome) {
            return $this->targetAttributes($goal, $period->month);
        }

        return [
            'target_rate' => $goal->monthly_target_rate,
            'resolved_target_amount' => $period->income_base === null
                ? null
                : MonthlyTargetResolver::applyRate($goal->monthly_target_rate, $period->income_base),
        ];
    }

    /**
     * Bring a goal up to today: open every month from its creation month
     * through the current one that has no period yet, then close every month
     * that is over.
     *
     * A gap left by a command that did not run is filled rather than skipped,
     * wherever it sits — an edit during the outage opens the current month
     * early, and the months before it still need theirs. Those months get the
     * target in force when they are filled, which is the target the goal had
     * all along unless it was edited during the outage.
     *
     * @return Collection<int, SavingsGoalPeriod> the periods this call closed, oldest first
     */
    public function advance(SavingsGoal $goal): Collection
    {
        foreach ($this->missingMonths($goal, $goal->periods()->pluck('month')) as $month) {
            $this->openPeriod($goal, $month);
        }

        return $this->closeOpenPeriods($goal, before: today()->startOfMonth());
    }

    /**
     * The months a read finds without a period — the daily command has not
     * opened them yet — as unsaved periods holding the target they would open
     * with. A page shows the month from the 1st without writing anything: only
     * the command and the write paths persist periods.
     *
     * @return Collection<int, SavingsGoalPeriod>
     */
    public function pendingPeriods(SavingsGoal $goal): Collection
    {
        return collect($this->missingMonths($goal, $goal->periods->pluck('month')))
            ->map(fn (Carbon $month): SavingsGoalPeriod => new SavingsGoalPeriod([
                'savings_goal_id' => $goal->id,
                'month' => $month->toDateString(),
                ...$this->targetAttributes($goal, $month),
            ]));
    }

    /**
     * Every month from the goal's creation month through the current one that
     * is not among $opened.
     *
     * @param  Collection<int, mixed>  $opened  the months that have a period
     * @return list<Carbon>
     */
    private function missingMonths(SavingsGoal $goal, Collection $opened): array
    {
        $currentMonth = today()->startOfMonth();
        $have = $opened->mapWithKeys(fn (mixed $month): array => [Carbon::parse($month)->format('Y-m') => true]);
        $missing = [];

        for ($cursor = $goal->created_at->copy()->startOfMonth(); $cursor->lte($currentMonth); $cursor->addMonthNoOverflow()) {
            if (! $have->has($cursor->format('Y-m'))) {
                $missing[] = $cursor->copy();
            }
        }

        return $missing;
    }

    /**
     * Close every open month of the goal, or only those before $before. An
     * archived goal closes all of them, the one in progress included, so a
     * share-of-income target stops moving with the income that comes after.
     *
     * @return Collection<int, SavingsGoalPeriod> the periods this call closed, oldest first
     */
    public function closeOpenPeriods(SavingsGoal $goal, ?Carbon $before = null): Collection
    {
        return $goal->periods()
            ->whereNull('closed_at')
            ->when($before, fn ($query) => $query->where('month', '<', $before->toDateString()))
            ->orderBy('month')
            ->get()
            ->filter(fn (SavingsGoalPeriod $period): bool => $this->close($goal, $period))
            ->values();
    }

    /**
     * Freeze the month's target and mark it closed. A share-of-income target
     * that was still followed live is frozen at the month's income so far.
     *
     * @return bool whether this call was the one that closed it
     */
    private function close(SavingsGoal $goal, SavingsGoalPeriod $period): bool
    {
        $frozen = [...$this->targets->current($period, $goal), 'closed_at' => now()];

        $claimed = SavingsGoalPeriod::query()
            ->whereKey($period->id)
            ->whereNull('closed_at')
            ->update([...$frozen, 'updated_at' => $frozen['closed_at']]);

        if ($claimed === 1) {
            $period->forceFill($frozen);
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
            ...$this->targets->resolveAtOpen($goal, $month),
        ];
    }
}
