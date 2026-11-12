<?php

namespace App\Http\Resources\V1;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One group of transactions: the values of the dimensions it was grouped by,
 * its currency and the metrics asked for.
 *
 * @property array<string, int|string|null> $resource
 */
#[SchemaName('AggregateBucket')]
class AggregateBucketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * The day, when grouped by `day`.
             *
             * @var string
             *
             * @format date
             *
             * @example 2026-10-04
             */
            'day' => $this->when(array_key_exists('day', $this->resource), fn (): mixed => $this->resource['day']),
            /**
             * The Monday the week starts on, when grouped by `week`. The first week can start before `from`.
             *
             * @var string
             *
             * @format date
             *
             * @example 2026-09-28
             */
            'week' => $this->when(array_key_exists('week', $this->resource), fn (): mixed => $this->resource['week']),
            /**
             * The month, as `YYYY-MM`, when grouped by `month`.
             *
             * @var string
             *
             * @example 2026-10
             */
            'month' => $this->when(array_key_exists('month', $this->resource), fn (): mixed => $this->resource['month']),
            /**
             * The year, when grouped by `year`.
             *
             * @var string
             *
             * @example 2026
             */
            'year' => $this->when(array_key_exists('year', $this->resource), fn (): mixed => $this->resource['year']),
            /**
             * The category's id, when grouped by `category`. `null` gathers the transactions with no category.
             *
             * @var string|null
             *
             * @format uuid
             *
             * @example 0199a3f5-2b3c-7d4e-8f5a-6b7c8d9e0f12
             */
            'category_id' => $this->when(array_key_exists('category_id', $this->resource), fn (): mixed => $this->resource['category_id']),
            /**
             * The category's name, when grouped by `category`.
             *
             * @var string|null
             *
             * @example Groceries
             */
            'category_name' => $this->when(array_key_exists('category_name', $this->resource), fn (): mixed => $this->resource['category_name']),
            /**
             * The label's id, when grouped by `label`. `null` gathers the transactions with no label. A transaction with several labels counts once in each of them.
             *
             * @var string|null
             *
             * @format uuid
             *
             * @example 0199a3f5-4c5d-7e6f-8a9b-0c1d2e3f4a5b
             */
            'label_id' => $this->when(array_key_exists('label_id', $this->resource), fn (): mixed => $this->resource['label_id']),
            /**
             * The label's name, when grouped by `label`.
             *
             * @var string|null
             *
             * @example Holidays
             */
            'label_name' => $this->when(array_key_exists('label_name', $this->resource), fn (): mixed => $this->resource['label_name']),
            /**
             * The account's id, when grouped by `account`.
             *
             * @var string
             *
             * @format uuid
             *
             * @example 0199a3f5-1a2b-7c3d-8e4f-5a6b7c8d9e0f
             */
            'account_id' => $this->when(array_key_exists('account_id', $this->resource), fn (): mixed => $this->resource['account_id']),
            /**
             * The account's name, when grouped by `account`.
             *
             * @var string
             *
             * @example Main account
             */
            'account_name' => $this->when(array_key_exists('account_name', $this->resource), fn (): mixed => $this->resource['account_name']),
            /**
             * The transactions' type, when grouped by `type`: `income`, `expense`, `transfer`, `savings` or `investment`.
             *
             * @var string
             *
             * @example expense
             */
            'type' => $this->when(array_key_exists('type', $this->resource), fn (): mixed => $this->resource['type']),
            /**
             * The currency of the bucket's amounts, as an ISO 4217 code. Amounts in different currencies are never added together: each currency gets its own bucket.
             *
             * @var string
             *
             * @example EUR
             */
            'currency' => $this->resource['currency'],
            /**
             * What the amounts add up to, signed, in minor units of `currency`: negative for money out. Only when asked for in `metrics`.
             *
             * @var int
             *
             * @example -15345
             */
            'sum' => $this->when(array_key_exists('sum', $this->resource), fn (): mixed => $this->resource['sum']),
            /**
             * How many transactions the bucket holds. Only when asked for in `metrics`.
             *
             * @var int
             *
             * @example 12
             */
            'count' => $this->when(array_key_exists('count', $this->resource), fn (): mixed => $this->resource['count']),
            /**
             * The average amount, rounded to a whole minor unit, or `null` for an empty bucket. Only when asked for in `metrics`.
             *
             * @var int|null
             *
             * @example -1279
             */
            'avg' => $this->when(array_key_exists('avg', $this->resource), fn (): mixed => $this->resource['avg']),
            /**
             * The smallest (most negative) amount, or `null` for an empty bucket. Only when asked for in `metrics`.
             *
             * @var int|null
             *
             * @example -4500
             */
            'min' => $this->when(array_key_exists('min', $this->resource), fn (): mixed => $this->resource['min']),
            /**
             * The largest amount, or `null` for an empty bucket. Only when asked for in `metrics`.
             *
             * @var int|null
             *
             * @example -250
             */
            'max' => $this->when(array_key_exists('max', $this->resource), fn (): mixed => $this->resource['max']),
        ];
    }
}
