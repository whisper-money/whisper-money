<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What was spent under one label in a period and in the period before it,
 * as positive amounts.
 *
 * @property array{id: string, name: string, color: string, amount: int, previous_amount: int, total_amount: int} $resource
 */
class LabelAmountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'label_id' => $this->resource['id'],
            'name' => $this->resource['name'],
            'color' => $this->resource['color'],
            'amount' => $this->resource['amount'],
            'previous_amount' => $this->resource['previous_amount'],
        ];
    }
}
