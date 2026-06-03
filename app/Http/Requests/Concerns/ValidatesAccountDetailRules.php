<?php

namespace App\Http\Requests\Concerns;

use App\Enums\AccountType;
use App\Enums\PropertyType;
use Illuminate\Validation\Rule;

trait ValidatesAccountDetailRules
{
    /**
     * Validation rules for real estate detail fields.
     *
     * @return array<string, array<mixed>>
     */
    protected function realEstateDetailRules(bool $propertyTypeSometimes = false, bool $withRevaluation = true): array
    {
        $rules = [
            'property_type' => [
                ...($propertyTypeSometimes ? ['sometimes'] : []),
                'required',
                'string',
                Rule::in(array_map(fn ($type) => $type->value, PropertyType::cases())),
            ],
            'address' => ['nullable', 'string', 'max:500'],
            'purchase_price' => ['nullable', 'integer', 'min:0'],
            'purchase_date' => ['nullable', 'date', 'before_or_equal:today'],
            'area_value' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'area_unit' => ['nullable', 'string', Rule::in(['sqm', 'sqft', 'acres', 'hectares'])],
            'linked_loan_account_id' => [
                'nullable',
                'string',
                $this->userOwnedAccountOfType(AccountType::Loan),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        if ($withRevaluation) {
            $rules['revaluation_percentage'] = ['nullable', 'numeric', 'min:-100', 'max:100'];
        }

        return $rules;
    }

    /**
     * Validation rules for loan detail fields.
     *
     * @return array<string, array<mixed>>
     */
    protected function loanDetailRules(): array
    {
        return [
            'annual_interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'loan_term_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'loan_start_date' => ['nullable', 'date'],
            'original_amount' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
