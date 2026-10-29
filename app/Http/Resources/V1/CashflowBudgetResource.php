<?php

namespace App\Http\Resources\V1;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A budget period in progress today that overlaps the cashflow's range, with
 * what is left of it.
 *
 * @property array{budget_id: string, budget_period_id: string, name: string, start_date: string, end_date: string, expense_type: string, allocated: int, spent: int, margin: int} $resource
 */
#[SchemaName('CashflowBudget')]
class CashflowBudgetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * The budget's id.
             *
             * @var string
             *
             * @format uuid
             *
             * @example 0199a3f8-4c5d-7e6f-8a9b-0c1d2e3f4a5b
             */
            'budget_id' => $this->resource['budget_id'],
            /**
             * The id of the budget's period in progress.
             *
             * @var string
             *
             * @format uuid
             *
             * @example 0199a3fa-1b2c-7d3e-8f4a-5b6c7d8e9f01
             */
            'budget_period_id' => $this->resource['budget_period_id'],
            /**
             * The budget's name.
             *
             * @var string
             *
             * @example Groceries
             */
            'name' => $this->resource['name'],
            /**
             * The period's first day.
             *
             * @var string
             *
             * @format date
             *
             * @example 2026-10-01
             */
            'start_date' => $this->resource['start_date'],
            /**
             * The period's last day, included.
             *
             * @var string
             *
             * @format date
             *
             * @example 2026-10-31
             */
            'end_date' => $this->resource['end_date'],
            /**
             * `fixed` or `variable`: which part of the expense split the budget's spending counts in.
             *
             * @var 'fixed'|'variable'
             *
             * @example variable
             */
            'expense_type' => $this->resource['expense_type'],
            /**
             * The period's limit, in minor units of `currency`.
             *
             * @var int
             *
             * @example 50000
             */
            'allocated' => $this->resource['allocated'],
            /**
             * What was spent against it so far.
             *
             * @var int
             *
             * @example 32050
             */
            'spent' => $this->resource['spent'],
            /**
             * `allocated` minus `spent`, negative once the period goes over its limit. Like the budgets endpoints, it leaves out any carried-over amount.
             *
             * @var int
             *
             * @example 17950
             */
            'margin' => $this->resource['margin'],
        ];
    }
}
