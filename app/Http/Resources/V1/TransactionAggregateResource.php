<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Your transactions of a date range, grouped and added up. Grouped by a
 * period, every period of the range is listed for every combination of the
 * other dimension and currency, with `sum` and `count` at `0` where nothing
 * was booked.
 *
 * @property array{group_by: list<string>, metrics: list<string>, from: string, to: string, buckets: list<array<string, int|string|null>>} $resource
 */
class TransactionAggregateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'group_by' => $this->resource['group_by'],
            'metrics' => $this->resource['metrics'],
            'from' => $this->resource['from'],
            'to' => $this->resource['to'],
            'buckets' => AggregateBucketResource::collection($this->resource['buckets']),
        ];
    }
}
