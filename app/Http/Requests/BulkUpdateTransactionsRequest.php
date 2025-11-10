<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkUpdateTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transaction_ids' => ['required', 'array', 'min:1'],
            'transaction_ids.*' => ['required', 'string', 'uuid'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'notes' => ['nullable', 'string'],
            'notes_iv' => ['nullable', 'string', 'size:16'],
        ];
    }

    public function messages(): array
    {
        return [
            'transaction_ids.required' => 'At least one transaction must be selected.',
            'transaction_ids.*.uuid' => 'Invalid transaction ID format.',
            'category_id.exists' => 'The selected category does not exist.',
            'notes_iv.size' => 'The notes IV must be exactly 16 characters.',
        ];
    }
}
