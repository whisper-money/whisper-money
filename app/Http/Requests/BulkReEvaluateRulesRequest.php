<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkReEvaluateRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transaction_ids' => ['nullable', 'array'],
            'transaction_ids.*' => ['required', 'string', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'transaction_ids.*.uuid' => 'Invalid transaction ID format.',
        ];
    }
}
