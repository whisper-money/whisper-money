<?php

namespace App\Http\Requests;

use App\Enums\CashflowBlock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $fields = ['category_ids', 'account_ids', 'label_ids'];

        foreach ($fields as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $this->merge([
                    $field => array_filter(explode(',', $value)),
                ]);
            }
        }
    }

    /**
     * A cashflow block is only defined over a period, so it needs both dates.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d', 'required_with:cashflow_block'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'required_with:cashflow_block'],
            'cashflow_block' => ['nullable', Rule::enum(CashflowBlock::class)],
            'amount_min' => ['nullable', 'numeric'],
            'amount_max' => ['nullable', 'numeric'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['string'],
            'account_ids' => ['nullable', 'array'],
            'account_ids.*' => ['string', 'uuid'],
            'label_ids' => ['nullable', 'array'],
            'label_ids.*' => ['string', 'uuid'],
            'creditor_name' => ['nullable', 'string', 'max:255'],
            'debtor_name' => ['nullable', 'string', 'max:255'],
            'category_source' => ['nullable', 'string', 'in:manual,rule,ai,bank'],
            'search' => ['nullable', 'string', 'max:200'],
            'cursor' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ];
    }

    /**
     * The validated filters in the shape `Transaction::applyFilters()` reads,
     * without the ones the request left out. The owner rides along because
     * the cashflow block filter decides expense blocks from their budgets.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return array_filter([
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'amount_min' => $validated['amount_min'] ?? null,
            'amount_max' => $validated['amount_max'] ?? null,
            'category_ids' => $validated['category_ids'] ?? null,
            'account_ids' => $validated['account_ids'] ?? null,
            'label_ids' => $validated['label_ids'] ?? null,
            'creditor_name' => $validated['creditor_name'] ?? null,
            'debtor_name' => $validated['debtor_name'] ?? null,
            'category_source' => $validated['category_source'] ?? null,
            'search' => $validated['search'] ?? null,
            'cashflow_block' => $validated['cashflow_block'] ?? null,
            'user_id' => $this->user()->id,
        ], fn ($value) => $value !== null);
    }
}
