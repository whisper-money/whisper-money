<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Label;

/**
 * The budget shape every budget tool returns, so list_budgets, create_budget and
 * update_budget all hand the agent the same row. Amounts are in minor units.
 */
trait PresentsBudgets
{
    /**
     * @return array<string, mixed>
     */
    protected function presentBudget(Budget $budget): array
    {
        $budget->loadMissing(['categories:id,name', 'labels:id,name']);

        $period = $budget->getCurrentPeriod();

        return [
            'id' => $budget->id,
            'name' => $budget->name,
            'period_type' => $budget->period_type->value,
            'period_start_day' => $budget->period_start_day,
            'rollover_type' => $budget->rollover_type->value,
            'is_catch_all' => $budget->is_catch_all,
            'categories' => $budget->categories->map(fn (Category $category): array => ['id' => $category->id, 'name' => $category->name])->all(),
            'labels' => $budget->labels->map(fn (Label $label): array => ['id' => $label->id, 'name' => $label->name])->all(),
            'current_period' => $period === null ? null : $this->presentBudgetPeriod($period),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentBudgetPeriod(BudgetPeriod $period): array
    {
        return [
            'id' => $period->id,
            'start_date' => $period->start_date->toDateString(),
            'end_date' => $period->end_date->toDateString(),
            'allocated_amount' => $period->allocated_amount,
            'carried_over_amount' => $period->carried_over_amount,
            'spent_amount' => $period->spentAmount(),
            'remaining_amount' => $period->remainingAmount(),
            // True while the queued backfill is still attaching the transactions
            // that were already in range, so an agent can tell "nothing spent
            // yet" apart from "not counted yet" and poll again.
            'processing_historical' => $period->processing_historical,
        ];
    }
}
