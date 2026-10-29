<?php

namespace App\Http\Requests\Api\V1\Concerns;

use Illuminate\Contracts\Validation\Validator;

/**
 * For the form requests whose only check after their rules is refusing body
 * fields they do not take.
 */
trait RefusesUnknownFields
{
    use AddsRefusals;

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->addRefusals($validator, $this->unknownFieldRefusals())];
    }
}
