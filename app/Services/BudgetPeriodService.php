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

    /**
     * Roll what is left of a finished period into the period that follows it.
     *
     * Looking the successor up is what makes this safe to run again: closing the
     * same period twice writes the same number to the same row. It used to ask
     * for a period instead, with no start date - which anchors to the end of the
     * chain, and the chain moves every time. So the nightly pass over every
     * period that had ever ended appended one row per closed period per run,
     * pushing one budget's periods out to the year 2143, and stamped the leftover
     * on that far-future row instead of the period the user is spending in.
     */
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

        $nextPeriod = $budget->periodFollowing($period)
            ?? $this->generatePeriod(
                $budget,
                $period->allocated_amount,
                $period->end_date->copy()->addDay(),
            );

        $nextPeriod->update(['carried_over_amount' => $carriedOverAmount]);
    }

    public function calculatePeriodDates(Budget $budget, Carbon $referenceDate): array
    {
        $startDate = $referenceDate->copy();

        switch ($budget->period_type) {
            case BudgetPeriodType::Monthly:
                $startDate->day($budget->period_start_day ?? 1);
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
