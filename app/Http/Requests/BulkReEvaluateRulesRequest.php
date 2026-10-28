<?php

namespace App\Http\Requests;

use App\Enums\CashflowBlock;
use App\Enums\CategoryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'filters' => ['nullable', 'array'],
            'filters.date_from' => ['nullable', 'date_format:Y-m-d', 'required_with:filters.cashflow_block'],
            'filters.date_to' => ['nullable', 'date_format:Y-m-d', 'required_with:filters.cashflow_block'],
            'filters.cashflow_block' => ['nullable', Rule::enum(CashflowBlock::class)],
            'filters.amount_min' => ['nullable', 'numeric'],
            'filters.amount_max' => ['nullable', 'numeric'],
            'filters.category_ids' => ['nullable', 'array'],
            'filters.category_ids.*' => ['string'],
            'filters.category_types' => ['nullable', 'array'],
            'filters.category_types.*' => [Rule::enum(CategoryType::class)],
            'filters.account_ids' => ['nullable', 'array'],
            'filters.account_ids.*' => ['string', 'uuid'],
            'filters.label_ids' => ['nullable', 'array'],
            'filters.label_ids.*' => ['string', 'uuid'],
            'filters.creditor_name' => ['nullable', 'string'],
            'filters.debtor_name' => ['nullable', 'string'],
            'filters.search' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'transaction_ids.*.uuid' => 'Invalid transaction ID format.',
        ];
    }
}
