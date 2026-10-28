<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\RefusesUnknownFields;
use App\Http\Requests\Api\V1\Concerns\ResolvesRouteBudget;
use Illuminate\Foundation\Http\FormRequest;

class DeleteBudgetRequest extends FormRequest
{
    use RefusesUnknownFields, ResolvesRouteBudget;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
