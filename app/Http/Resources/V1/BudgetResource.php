<?php

namespace App\Http\Resources\V1;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Label;
use App\Models\User;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A budget: a spending limit per period over a set of categories and labels,
 * with the period in progress.
 *
 * @mixin Budget
 */
#[SchemaName('Budget')]
class BudgetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        $this->resource->loadMissing(['categories:id,name', 'labels:id,name']);

        return [
            /**
             * The budget's id.
             *
             * @format uuid
             *
             * @example 0199a3f8-4c5d-7e6f-8a9b-0c1d2e3f4a5b
             */
            'id' => $this->id,
            /**
             * The budget's name.
             *
             * @example Groceries
             */
            'name' => $this->name,
            /**
             * How often the budget starts over: `monthly`, `weekly`, `biweekly`, `quarterly` or `yearly`. Fixed once created.
             *
             * @example monthly
             */
            'period_type' => $this->period_type->value,
            /**
             * The day a period starts: the day of the month (1–31) for a monthly budget, the day of the week (0 is Sunday) for a weekly or biweekly one. Quarterly and yearly budgets follow the calendar. `null` when not set. Fixed once created.
             *
             * @example 1
             */
            'period_start_day' => $this->period_start_day,
            /**
             * What happens to what is left at the end of a period: `carry_over` adds it to the next period, `reset` starts every period at the limit. Fixed once created.
             *
             * @example reset
             */
            'rollover_type' => $this->rollover_type->value,
            /**
             * `fixed` for spending that repeats at the same amount every period (rent, subscriptions), `variable` for spending you can adjust (groceries, leisure). It decides how the cashflow analytics split your expenses. A catch-all budget is always `variable`.
             *
             * @example variable
             */
            'expense_type' => $this->expense_type->value,
            /**
             * Whether the budget tracks every expense category no other budget tracks. An account has at most one. Fixed once created.
             */
            'is_catch_all' => $this->is_catch_all,
            /**
             * The categories the budget tracks; tracking a parent category also tracks its children. Fixed once created.
             *
             * @example [{"id": "0199a3f5-2b3c-7d4e-8f5a-6b7c8d9e0f12", "name": "Groceries"}]
             */
            'categories' => $this->categories->map(fn (Category $category): array => ['id' => $category->id, 'name' => $category->name])->values()->all(),
            /**
             * The labels the budget tracks. Fixed once created.
             *
             * @example [{"id": "0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23", "name": "Essentials"}]
             */
            'labels' => $this->labels->map(fn (Label $label): array => ['id' => $label->id, 'name' => $label->name])->values()->all(),
            /**
             * The currency of the budget's amounts: your account's currency, as an ISO 4217 code.
             *
             * @example EUR
             */
            'currency' => $user->currency_code ?? 'USD',
            /**
             * The period that covers today, or `null` when no period does.
             */
            'current_period' => $this->currentPeriod(),
            /**
             * When the budget was archived in the app, or `null`. An archived budget keeps its history but can no longer change; it can still be deleted.
             *
             * @format date-time
             *
             * @example null
             */
            'archived_at' => $this->archived_at?->toIso8601String(),
            /**
             * When the budget was created.
             *
             * @format date-time
             *
             * @example 2026-01-15T09:30:00+00:00
             */
            'created_at' => $this->created_at?->toIso8601String(),
            /**
             * When the budget was last changed.
             *
             * @format date-time
             *
             * @example 2026-01-20T18:05:00+00:00
             */
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The period covering today, read from the `currentPeriods` a list eager
     * loads, with its spend summed, when it did.
     */
    private function currentPeriod(): ?BudgetPeriodResource
    {
        $period = $this->resource->loadMissing('currentPeriods')->currentPeriods->first();

        return $period === null ? null : new BudgetPeriodResource($period);
    }
}
