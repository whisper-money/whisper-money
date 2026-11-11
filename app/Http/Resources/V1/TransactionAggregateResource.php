<?php

namespace App\Http\Resources\V1;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Your transactions of a date range, grouped and added up.
 *
 * @property array{group_by: list<string>, metrics: list<string>, from: string, to: string, buckets: list<array<string, int|string|null>>} $resource
 */
#[SchemaName('TransactionAggregate')]
class TransactionAggregateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * The dimensions the buckets are grouped by, in the order asked for.
             *
             * @var list<string>
             *
             * @example ["month", "category"]
             */
            'group_by' => $this->resource['group_by'],
            /**
             * The metrics every bucket carries.
             *
             * @var list<string>
             *
             * @example ["sum", "count"]
             */
            'metrics' => $this->resource['metrics'],
            /**
             * The first day of the range.
             *
             * @format date
             *
             * @example 2026-10-01
             */
            'from' => $this->resource['from'],
            /**
             * The last day of the range.
             *
             * @format date
             *
             * @example 2026-10-31
             */
            'to' => $this->resource['to'],
            /**
             * The groups, oldest period first and then by name. Grouped by a period, every period of the range is listed for every combination of the other dimension and currency, with `sum` and `count` at `0` where nothing was booked.
             */
            'buckets' => AggregateBucketResource::collection($this->resource['buckets']),
        ];
    }
}
