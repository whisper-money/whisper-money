<?php

namespace App\Http\Requests\Api\V1\Concerns;

use Illuminate\Contracts\Validation\Validator;

/**
 * What the label create and update requests share: the LabelWriter rules the
 * MCP tools validate with too.
 */
trait ValidatesLabelWrites
{
    use AddsRefusals;

    /**
     * @param  array<string, array<mixed>>  $rules  the LabelWriter rules for the write
     * @return array<string, array<mixed>>
     */
    protected function labelFields(array $rules): array
    {
        return [
            /**
             * The label's name, unique within the space.
             *
             * @example Holidays
             */
            'name' => $rules['name'],
            /**
             * The label's color.
             *
             * @example blue
             */
            'color' => $rules['color'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->addRefusals($validator, $this->unknownFieldRefusals())];
    }
}
