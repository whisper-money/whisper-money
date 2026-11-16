<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One group of transactions: the values of the dimensions it was grouped by,
 * its currency and the metrics asked for, always in the same key order.
 * `category_id` and `label_id` are null for the transactions with no category
 * or label; a week is named by its Monday, a month as `YYYY-MM`. Amounts are
 * signed, in minor units of `currency`; `avg`, `min` and `max` are null on an
 * empty bucket.
 *
 * @property array<string, int|string|null> $resource
 */
class AggregateBucketResource extends JsonResource
{
    /**
     * Every key a bucket can carry, in the order a response gives them.
     */
    private const KEYS = [
        'day', 'week', 'month', 'year',
        'category_id', 'category_name', 'label_id', 'label_name', 'account_id', 'account_name', 'type',
        'currency',
        'sum', 'count', 'avg', 'min', 'max',
    ];

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(Request $request): array
    {
        $bucket = [];

        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $this->resource)) {
                $bucket[$key] = $this->resource[$key];
            }
        }

        return $bucket;
    }
}
