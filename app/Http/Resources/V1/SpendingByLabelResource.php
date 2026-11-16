<?php

namespace App\Http\Resources\V1;

use App\Services\PeriodComparator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Your expenses in a date range by label, largest first, against the
 * previous period. A transaction counts under every label it carries, so
 * `total` can be above what you spent; savings goal labels are left out.
 *
 * @property array{period: PeriodComparator, labels: list<array{id: string, name: string, color: string, amount: int, previous_amount: int, total_amount: int}>} $resource
 */
class SpendingByLabelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $previous = $this->resource['period']->previous();

        return [
            'currency' => $request->user()->currency_code ?? 'USD',
            'total' => $this->resource['labels'][0]['total_amount'] ?? 0,
            'previous_from' => $previous->from->toDateString(),
            'previous_to' => $previous->to->toDateString(),
            'labels' => LabelAmountResource::collection($this->resource['labels']),
        ];
    }
}
