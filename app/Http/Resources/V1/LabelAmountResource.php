<?php

namespace App\Http\Resources\V1;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What was spent under one label in a period and in the period before it.
 *
 * @property array{id: string, name: string, color: string, amount: int, previous_amount: int, total_amount: int} $resource
 */
#[SchemaName('LabelAmount')]
class LabelAmountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * The label's id.
             *
             * @format uuid
             *
             * @example 0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23
             */
            'label_id' => $this->resource['id'],
            /**
             * The label's name.
             *
             * @example Holidays
             */
            'name' => $this->resource['name'],
            /**
             * The label's colour in the app.
             *
             * @example blue
             */
            'color' => $this->resource['color'],
            /**
             * What was spent under the label over the range, as a positive amount in minor units of `currency`.
             *
             * @var int
             *
             * @example 48250
             */
            'amount' => $this->resource['amount'],
            /**
             * What was spent under the label over the previous period, `0` when nothing was.
             *
             * @var int
             *
             * @example 31000
             */
            'previous_amount' => $this->resource['previous_amount'],
        ];
    }
}
