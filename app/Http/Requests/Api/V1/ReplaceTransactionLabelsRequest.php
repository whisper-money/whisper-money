<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\AddsRefusals;
use App\Http\Requests\Api\V1\Concerns\ChecksIdsInSpace;
use App\Http\Requests\Api\V1\Concerns\ResolvesRouteTransaction;
use App\Models\Label;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ReplaceTransactionLabelsRequest extends FormRequest
{
    use AddsRefusals, ChecksIdsInSpace, ResolvesRouteTransaction;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            /**
             * The ids of every label the transaction should carry, each a label of the space, up to 100. The transaction ends up with exactly these: a label left out is removed, and an empty list removes them all.
             *
             * @example ["0199a3f7-3b4c-7d5e-8f6a-7b8c9d0e1f2a"]
             */
            'label_ids' => ['present', 'array', 'max:100'],
            /**
             * @format uuid
             */
            'label_ids.*' => ['string', 'distinct:ignore_case', $this->inSpace(Label::class, 'a label')],
        ];
    }

    /**
     * Refuse unknown fields.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->addRefusals($validator, $this->unknownFieldRefusals())];
    }
}
