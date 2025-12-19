<?php

namespace App\Services;

use App\Enums\BudgetPeriodType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use Carbon\Carbon;

class BudgetPeriodService
{
    public function generatePeriod(Budget $budget, ?Carbon $startDate = null): BudgetPeriod
    {
        if ($startDate === null) {
            $startDate = $this->calculateNextPeriodStartDate($budget);
        }

        [$periodStart, $periodEnd] = $this->calculatePeriodDates($budget, $startDate);

        return BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => $periodStart,
            'end_date' => $periodEnd,
            'carried_over_amount' => 0,
        ]);
    }

    public function closePeriod(BudgetPeriod $period): void
    {
        $budget = $period->budget;
        $carriedOverAmount = 0;

        foreach ($period->allocations as $allocation) {
            $remaining = $allocation->calculateRemaining();
            $budgetCategory = $allocation->budgetCategory;

            if ($budgetCategory->rollover_type->value === 'carry_over') {
                $carriedOverAmount += $remaining;
            }
        }

        $nextPeriod = $this->generatePeriod($budget);
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
                $dayOfWeek = $budget->period_start_day ?? 0;
                while ($startDate->dayOfWeek !== $dayOfWeek) {
                    $startDate->subDay();
                }
                $endDate = $startDate->copy()->addWeek()->subDay();
                break;

            case BudgetPeriodType::Biweekly:
                $dayOfWeek = $budget->period_start_day ?? 0;
                while ($startDate->dayOfWeek !== $dayOfWeek) {
                    $startDate->subDay();
                }
                $endDate = $startDate->copy()->addWeeks(2)->subDay();
                break;

            case BudgetPeriodType::Custom:
                $duration = $budget->period_duration ?? 30;
                $startDate->day($budget->period_start_day ?? 1);
                if ($startDate > $referenceDate) {
                    $startDate->subDays($duration);
                }
                $endDate = $startDate->copy()->addDays($duration)->subDay();
                break;

            default:
                $endDate = $startDate->copy()->addMonth()->subDay();
        }

        return [$startDate, $endDate];
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

