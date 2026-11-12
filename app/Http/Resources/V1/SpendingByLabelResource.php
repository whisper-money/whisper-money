<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use App\Services\PeriodComparator;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Your expenses in a date range by label, against the previous period.
 *
 * @property array{period: PeriodComparator, labels: list<array{id: string, name: string, color: string, amount: int, previous_amount: int, total_amount: int}>} $resource
 */
#[SchemaName('SpendingByLabel')]
class SpendingByLabelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $previous = $this->resource['period']->previous();

        return [
            /**
             * The currency of every amount: your account's currency, as an ISO 4217 code.
             *
             * @example EUR
             */
            'currency' => $user->currency_code ?? 'USD',
            /**
             * What `labels` adds up to. A transaction counts under every label it carries, so this can be above what you spent in the range.
             *
             * @var int
             *
             * @example 79250
             */
            'total' => $this->resource['labels'][0]['total_amount'] ?? 0,
            /**
             * The first day of the previous period `previous_amount` reads: the same number of days right before `from`, or the same number of whole months when the range is whole months.
             *
             * @format date
             *
             * @example 2026-09-01
             */
            'previous_from' => $previous->from->toDateString(),
            /**
             * The last day of the previous period.
             *
             * @format date
             *
             * @example 2026-09-30
             */
            'previous_to' => $previous->to->toDateString(),
            /**
             * The labels with spending in the range, largest first. Only the ones whose spending is above zero, and never the label of a savings goal, which tracks contributions rather than spending.
             */
            'labels' => LabelAmountResource::collection($this->resource['labels']),
        ];
    }
}
