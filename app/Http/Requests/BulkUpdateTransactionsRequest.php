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
            'transaction_ids' => ['array', 'min:1'],
            'transaction_ids.*' => ['required', 'string', 'uuid'],
            'filters' => ['array'],
            'filters.date_from' => ['nullable', 'date'],
            'filters.date_to' => ['nullable', 'date'],
            'filters.amount_min' => ['nullable', 'numeric'],
            'filters.amount_max' => ['nullable', 'numeric'],
            'filters.category_ids' => ['nullable', 'array'],
            'filters.category_ids.*' => ['string', 'uuid', 'exists:categories,id'],
            'filters.account_ids' => ['nullable', 'array'],
            'filters.account_ids.*' => ['string', 'uuid'],
            'filters.label_ids' => ['nullable', 'array'],
            'filters.label_ids.*' => ['string', 'uuid', 'exists:labels,id'],
            'filters.search_text' => ['nullable', 'string'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'notes' => ['nullable', 'string'],
            'notes_iv' => ['nullable', 'string', 'size:16'],
            'label_ids' => ['nullable', 'array'],
            'label_ids.*' => ['required', 'string', 'uuid', 'exists:labels,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'transaction_ids.*.uuid' => 'Invalid transaction ID format.',
            'category_id.exists' => 'The selected category does not exist.',
            'notes_iv.size' => 'The notes IV must be exactly 16 characters.',
        ];
    }
}
