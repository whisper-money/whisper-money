<?php

namespace App\Http\Requests\Concerns;

use App\Enums\AccountType;
use App\Enums\PropertyType;
use App\Models\Account;
use Closure;
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
     * The three columns behind a loan detail are NOT NULL and the amortization
     * projection needs all three, so a partial set cannot be stored at all.
     * `$requireCompleteSet` says so out loud instead of letting the rate and
     * the term a user typed be dropped on the floor with the account created
     * and no error shown. Pass it wherever there is no detail row yet; an
     * existing one takes a partial update fine.
     *
     * @return array<string, array<mixed>>
     */
    protected function loanDetailRules(bool $requireCompleteSet = false): array
    {
        $completeSet = fn (string ...$others): array => $requireCompleteSet
            ? ['required_with:'.implode(',', [...$others, 'loan_start_date'])]
            : [];

        return [
            'annual_interest_rate' => ['nullable', ...$completeSet('loan_term_months', 'original_amount'), 'numeric', 'min:0', 'max:100'],
            'loan_term_months' => ['nullable', ...$completeSet('annual_interest_rate', 'original_amount'), 'integer', 'min:1', 'max:600'],
            // Floor the date for the same reason as purchase_date above: an
            // ancient loan start date OOMs the balance generator (PHP-LARAVEL-49).
            'loan_start_date' => ['nullable', 'date', 'after_or_equal:1900-01-01'],
            'original_amount' => ['nullable', ...$completeSet('annual_interest_rate', 'loan_term_months'), 'integer', 'min:0'],
        ];
    }

    /**
     * Rules for the property a loan is the mortgage of: it has to be one of the
     * user's real estate accounts, it has to have a detail row to hang the link
     * off, and it cannot already be answering to another loan.
     *
     * @return array<string, array<mixed>>
     */
    protected function linkedRealEstateAccountRules(): array
    {
        return [
            'linked_real_estate_account_id' => [
                'nullable',
                'string',
                $this->userOwnedAccountOfType(AccountType::RealEstate),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }

                    $account = Account::query()
                        ->whereKey($value)
                        ->where('user_id', $this->user()->id)
                        ->where('type', AccountType::RealEstate->value)
                        ->with('realEstateDetail')
                        ->first();

                    if (! $account?->realEstateDetail) {
                        $fail(__('The selected property cannot be linked.'));

                        return;
                    }

                    if ($account->realEstateDetail->linked_loan_account_id !== null) {
                        $fail(__('The selected property is already linked to a loan.'));
                    }
                },
            ],
        ];
    }
}
