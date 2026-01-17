<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'filters.category_ids.*' => [
                'string',
                'uuid',
                Rule::exists('categories', 'id')->where(function ($query) {
                    $query->where('user_id', $this->user()->id);
                }),
            ],
            'filters.account_ids' => ['nullable', 'array'],
            'filters.account_ids.*' => ['string', 'uuid'],
            'filters.label_ids' => ['nullable', 'array'],
            'filters.label_ids.*' => [
                'string',
                'uuid',
                Rule::exists('labels', 'id')->where(function ($query) {
                    $query->where('user_id', $this->user()->id);
                }),
            ],
            'filters.search_text' => ['nullable', 'string'],
            'category_id' => [
                'nullable',
                Rule::exists('categories', 'id')->where(function ($query) {
                    $query->where('user_id', $this->user()->id);
                }),
            ],
            'notes' => ['nullable', 'string'],
            'notes_iv' => ['nullable', 'string', 'size:16'],
            'label_ids' => ['nullable', 'array'],
            'label_ids.*' => [
                'required',
                'string',
                'uuid',
                Rule::exists('labels', 'id')->where(function ($query) {
                    $query->where('user_id', $this->user()->id);
                }),
            ],
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
