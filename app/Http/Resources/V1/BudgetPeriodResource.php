<?php

namespace App\Http\Resources\V1;

use App\Models\BudgetPeriod;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One period of a budget: the stretch of days its limit applies to, and what
 * was spent against it. Amounts are in the minor units of the budget's
 * `currency`.
 *
 * @mixin BudgetPeriod
 */
#[SchemaName('BudgetPeriod')]
class BudgetPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $spent = $this->spentAmount();

        return [
            /**
             * The period's id.
             *
             * @format uuid
             *
             * @example 0199a3fa-1b2c-7d3e-8f4a-5b6c7d8e9f01
             */
            'id' => $this->id,
            /**
             * The period's first day.
             *
             * @format date
             *
             * @example 2026-10-01
             */
            'start_date' => $this->start_date->toDateString(),
            /**
             * The period's last day, included.
             *
             * @format date
             *
             * @example 2026-10-31
             */
            'end_date' => $this->end_date->toDateString(),
            /**
             * The limit for this period.
             *
             * @example 50000
             */
            'allocated_amount' => $this->allocated_amount,
            /**
             * What the previous period left over, carried into this one when the budget's `rollover_type` is `carry_over`.
             *
             * @example 0
             */
            'carried_over_amount' => $this->carried_over_amount,
            /**
             * What the tracked transactions of the period add up to, as a positive number; a refund lowers it. Provisional while `processing_historical` is `true`.
             *
             * @example 32050
             */
            'spent_amount' => $spent,
            /**
             * `allocated_amount` minus `spent_amount`, the figure the app shows. It leaves out `carried_over_amount`, and it is negative once the period goes over its limit.
             *
             * @example 17950
             */
            'remaining_amount' => $this->allocated_amount - $spent,
            /**
             * `true` while the transactions that were already in the period when the budget was created are still being added to it, in the background. Until then `spent_amount` and `remaining_amount` are provisional: read the budget again in a few seconds.
             */
            'processing_historical' => $this->processing_historical,
        ];
    }
}
