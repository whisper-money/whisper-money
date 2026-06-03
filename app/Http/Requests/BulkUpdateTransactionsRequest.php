<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use Illuminate\Foundation\Http\FormRequest;

class BulkUpdateTransactionsRequest extends FormRequest
{
    use ValidatesUserOwnedResources;

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
            'filters.category_ids.*' => ['string', 'uuid', $this->userOwned('categories')],
            'filters.account_ids' => ['nullable', 'array'],
            'filters.account_ids.*' => ['string', 'uuid'],
            'filters.label_ids' => ['nullable', 'array'],
            'filters.label_ids.*' => ['string', 'uuid', $this->userOwned('labels')],
            'filters.creditor_name' => ['nullable', 'string'],
            'filters.debtor_name' => ['nullable', 'string'],
            'filters.search' => ['nullable', 'string'],
            'filters.search_text' => ['nullable', 'string'],
            'category_id' => ['nullable', $this->userOwned('categories')],
            'notes' => ['nullable', 'string'],
            'notes_iv' => ['nullable', 'string', 'size:16'],
            'label_ids' => ['nullable', 'array'],
            'label_ids.*' => ['required', 'string', 'uuid', $this->userOwned('labels')],
        ];
    }

    public function messages(): array
    {
        return [
            'transaction_ids.*.uuid' => 'Invalid transaction ID format.',
            'category_id.exists' => 'The selected category does not exist or does not belong to you.',
            'filters.category_ids.*.exists' => 'One or more filter categories do not exist or do not belong to you.',
            'filters.label_ids.*.exists' => 'One or more filter labels do not exist or do not belong to you.',
            'label_ids.*.exists' => 'One or more selected labels do not exist or do not belong to you.',
            'notes_iv.size' => 'The notes IV must be exactly 16 characters.',
        ];
    }
}
