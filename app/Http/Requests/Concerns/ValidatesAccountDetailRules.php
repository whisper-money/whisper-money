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
            // Floor the date: a mistyped/ancient year would make the historical
            // balance generator build a multi-century monthly series and OOM the
            // queue worker (PHP-LARAVEL-49). 1900 rejects typos, not real assets.
            'purchase_date' => ['nullable', 'date', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
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
            // Floor the date for the same reason as purchase_date above: an
            // ancient loan start date OOMs the balance generator (PHP-LARAVEL-49).
            'loan_start_date' => ['nullable', 'date', 'after_or_equal:1900-01-01'],
            'original_amount' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
