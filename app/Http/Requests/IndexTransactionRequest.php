<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
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
            'sort' => ['nullable', 'string', 'in:transaction_date,-transaction_date,amount,-amount,description,-description,creditor_name,-creditor_name,debtor_name,-debtor_name'],
            'cursor' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ];
    }
}
