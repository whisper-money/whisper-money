<?php

namespace App\Services;

use App\Enums\BudgetPeriodType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use Carbon\Carbon;

class BudgetPeriodService
{
    public function generatePeriod(Budget $budget, ?int $allocatedAmount = null, ?Carbon $startDate = null, bool $processHistorical = false): BudgetPeriod
    {
        if ($startDate === null) {
            $startDate = $this->calculateNextPeriodStartDate($budget);
        }

        [$periodStart, $periodEnd] = $this->calculatePeriodDates($budget, $startDate);

        $periodStart = $periodStart->startOfDay();
        $periodEnd = $periodEnd->startOfDay();

        // If no allocated amount provided, use the last period's amount or 0
        if ($allocatedAmount === null) {
            $lastPeriod = $budget->periods()->orderBy('end_date', 'desc')->first();
            $allocatedAmount = $lastPeriod !== null ? $lastPeriod->allocated_amount : 0;
        }

        // Idempotent on the (budget_id, start_date) unique key: the scheduled
        // command can recompute the same next start date across overlapping or
        // repeated runs, so return the existing period instead of colliding.
        return BudgetPeriod::firstOrCreate(
            [
                'budget_id' => $budget->id,
                'start_date' => $periodStart,
            ],
            [
                'end_date' => $periodEnd,
                'allocated_amount' => $allocatedAmount,
                'carried_over_amount' => 0,
                'processing_historical' => $processHistorical,
            ],
        );
    }

    public function generatePreviousPeriod(Budget $budget, BudgetPeriod $period, ?int $allocatedAmount = null, bool $processHistorical = false): BudgetPeriod
    {
        $referenceDate = $period->start_date->copy()->subDay();

        return $this->generatePeriod($budget, $allocatedAmount ?? $period->allocated_amount, $referenceDate, $processHistorical);
    }

    public function closePeriod(BudgetPeriod $period): void
    {
        $budget = $period->budget;
        $carriedOverAmount = 0;

        if ($budget->rollover_type->value === 'carry_over') {
            $remaining = $period->remainingAmount();

            if ($remaining > 0) {
                $carriedOverAmount = $remaining;
            }
        }

        $nextPeriod = $this->generatePeriod($budget, $period->allocated_amount);
        $nextPeriod->update(['carried_over_amount' => $carriedOverAmount]);
    }

    public function calculatePeriodDates(Budget $budget, Carbon $referenceDate): array
    {
        $startDate = $referenceDate->copy();

        switch ($budget->period_type) {
            case BudgetPeriodType::Monthly:
                $startDate->day($this->startDayOfMonth($budget));
                if ($startDate > $referenceDate) {
                    $startDate->subMonth();
                }
                $endDate = $startDate->copy()->addMonth()->subDay();
                break;

            case BudgetPeriodType::Weekly:
                $startDate = $this->rewindToDayOfWeek($startDate, $budget->period_start_day);
                $endDate = $startDate->copy()->addWeek()->subDay();
                break;

            case BudgetPeriodType::Biweekly:
                $startDate = $this->rewindToDayOfWeek($startDate, $budget->period_start_day);
                $endDate = $startDate->copy()->addWeeks(2)->subDay();
                break;

            case BudgetPeriodType::Yearly:
                $startDate->startOfYear();
                $endDate = $startDate->copy()->addYear()->subDay();
                break;
        }

        return [$startDate, $endDate];
    }

    /**
     * The budget's start day as a real day of the month.
     *
     * `period_start_day` is validated as 0-31 for every period type, because for
     * weekly and biweekly budgets it is a day of the week and 0 is Sunday. For a
     * monthly budget that 0 reaches `Carbon::day(0)`, which resolves to the last
     * day of the *previous* month - and chained generation can never escape it:
     * the reference date is the previous period's end plus one day, `day(0)`
     * snaps back to the period that already exists, and `firstOrCreate` hands
     * back that same row forever. Three live budgets have been frozen that way
     * since 2026-07-30, with no current period at all.
     *
     * Only the floor is applied, and the missing ceiling is its own bug rather
     * than a tidy carve-out. A day past the end of a short month overflows into
     * the next one (February plus 31 days is 3 March) and `subMonth` walks the
     * window back from there, which can return a window that *starts after* the
     * reference date: day 30 asked for on 1 February yields 2 February to 1
     * March. Four live budgets are anchored at 30 or 31, and they meet it on the
     * on-demand path only - a budget created that day renders "No active period"
     * until the following day, rather than freezing. Fixing it needs a clamp
     * *and* month arithmetic that does not overflow, which moves windows that
     * currently work, so it needs its own change and its own repair.
     */
    private function startDayOfMonth(Budget $budget): int
    {
        return max(1, (int) $budget->period_start_day);
    }

    /**
     * Step back to the most recent $dayOfWeek. Taken modulo 7 because
     * `period_start_day` holds a day of the month for monthly budgets, so a
     * budget switched to a weekly period can carry a value above 6 — which
     * `Carbon::dayOfWeek` would never match, spinning forever.
     */
    private function rewindToDayOfWeek(Carbon $date, ?int $dayOfWeek): Carbon
    {
        $target = ($dayOfWeek ?? 0) % 7;

        while ($date->dayOfWeek !== $target) {
            $date->subDay();
        }

        return $date;
    }

    protected function calculateNextPeriodStartDate(Budget $budget): Carbon
    {
        $lastPeriod = $budget->periods()->orderBy('end_date', 'desc')->first();

        if ($lastPeriod) {
            return $lastPeriod->end_date->copy()->addDay();
        }

        return now();
    }
}
