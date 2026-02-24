<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BulkUpdateTransactionRequest extends FormRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'transactions' => ['required', 'array', 'min:1', 'max:50'],
            'transactions.*.id' => ['required', 'uuid'],
            'transactions.*.description' => ['sometimes', 'string'],
            'transactions.*.notes' => ['sometimes', 'nullable', 'string'],
            'transactions.*.description_iv' => ['sometimes', 'nullable', 'string'],
            'transactions.*.notes_iv' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
