<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSavedFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'filters' => ['required', 'array'],
            'filters.date_from' => ['nullable', 'date'],
            'filters.date_to' => ['nullable', 'date'],
            'filters.amount_min' => ['nullable', 'numeric'],
            'filters.amount_max' => ['nullable', 'numeric'],
            'filters.category_ids' => ['nullable', 'array'],
            'filters.category_ids.*' => ['string'],
            'filters.account_ids' => ['nullable', 'array'],
            'filters.account_ids.*' => ['string'],
            'filters.label_ids' => ['nullable', 'array'],
            'filters.label_ids.*' => ['string'],
            'filters.creditor_name' => ['nullable', 'string', 'max:255'],
            'filters.debtor_name' => ['nullable', 'string', 'max:255'],
            'filters.search' => ['nullable', 'string', 'max:200'],
        ];
    }
}
