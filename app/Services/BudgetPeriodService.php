<?php

namespace App\Services;

use App\Enums\BudgetPeriodType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use Carbon\Carbon;

class BudgetPeriodService
{
    public function generatePeriod(Budget $budget, ?int $allocatedAmount = null, ?Carbon $startDate = null): BudgetPeriod
    {
        if ($startDate === null) {
            $startDate = $this->calculateNextPeriodStartDate($budget);
        }

        [$periodStart, $periodEnd] = $this->calculatePeriodDates($budget, $startDate);

        // If no allocated amount provided, use the last period's amount or 0
        if ($allocatedAmount === null) {
            $lastPeriod = $budget->periods()->orderBy('end_date', 'desc')->first();
            $allocatedAmount = $lastPeriod?->allocated_amount ?? 0;
        }

        return BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => $periodStart,
            'end_date' => $periodEnd,
            'allocated_amount' => $allocatedAmount,
            'carried_over_amount' => 0,
        ]);
    }

    public function closePeriod(BudgetPeriod $period): void
    {
        $budget = $period->budget;
        $carriedOverAmount = 0;

        if ($budget->rollover_type->value === 'carry_over') {
            $totalSpent = $period->budgetTransactions()->sum('amount');
            $remaining = $period->allocated_amount - abs($totalSpent);
            
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
