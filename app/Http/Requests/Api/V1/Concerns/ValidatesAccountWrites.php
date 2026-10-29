<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Enums\AccountType;
use App\Models\User;
use App\Services\Accounts\AccountWriteRules;

/**
 * What the account create and update requests share: the AccountWriteRules
 * the MCP tools validate with too, and the real estate, loan and credit card
 * detail fields, so an account of any type can be described in one request.
 */
trait ValidatesAccountWrites
{
    use AddsRefusals;

    protected function accountRules(): AccountWriteRules
    {
        /** @var User|null $user */
        $user = $this->user();

        return new AccountWriteRules($user);
    }

    /**
     * The type the request asks for, null when it sends none or an invalid one.
     */
    protected function requestedType(): ?AccountType
    {
        return AccountType::tryFrom($this->string('type')->toString());
    }

    /**
     * The detail fields. One that does not belong to the account's type is
     * ignored.
     *
     * @param  array<string, array<mixed>>  $rules  the AccountWriteRules for the account's type
     * @return array<string, array<mixed>>
     */
    protected function detailFields(array $rules): array
    {
        return [
            /**
             * `real_estate` only: what kind of property it is. Required when the property's details are first created.
             */
            'property_type' => $rules['property_type'] ?? ['exclude'],
            /**
             * `real_estate` only: the property's address.
             */
            'address' => $rules['address'] ?? ['exclude'],
            /**
             * `real_estate` only: what it was bought for, in minor units. With `purchase_date` and a `balance`, the value history in between is filled in.
             */
            'purchase_price' => $rules['purchase_price'] ?? ['exclude'],
            /**
             * `real_estate` only: when it was bought. Not in the future, not before 1900.
             *
             * @format date
             */
            'purchase_date' => $rules['purchase_date'] ?? ['exclude'],
            /**
             * `real_estate` only: floor area, in `area_unit`.
             */
            'area_value' => $rules['area_value'] ?? ['exclude'],
            /**
             * `real_estate` only: the unit of `area_value`.
             */
            'area_unit' => $rules['area_unit'] ?? ['exclude'],
            /**
             * `real_estate` only: expected yearly revaluation, as a percentage.
             */
            'revaluation_percentage' => $rules['revaluation_percentage'] ?? ['exclude'],
            /**
             * `real_estate` only: free-text notes on the property.
             */
            'notes' => $rules['notes'] ?? ['exclude'],
            /**
             * `real_estate` only: id of one of your `loan` accounts that is this property's mortgage.
             *
             * @format uuid
             */
            'linked_loan_account_id' => $rules['linked_loan_account_id'] ?? ['exclude'],
            /**
             * `loan` only: yearly interest rate, as a percentage.
             */
            'annual_interest_rate' => $rules['annual_interest_rate'] ?? ['exclude'],
            /**
             * `loan` only: the term, in months.
             */
            'loan_term_months' => $rules['loan_term_months'] ?? ['exclude'],
            /**
             * `loan` only: the amount originally borrowed, in minor units. A loan's details are created once `annual_interest_rate`, `loan_term_months` and `original_amount` are all known.
             */
            'original_amount' => $rules['original_amount'] ?? ['exclude'],
            /**
             * `loan` only: when the loan started. Defaults to today.
             *
             * @format date
             */
            'loan_start_date' => $rules['loan_start_date'] ?? ['exclude'],
            /**
             * `credit_card` only: a statement closing date. Later cycles repeat from it at `statement_frequency`. Send it with `payment_due_date`; both `null` clears them.
             *
             * @format date
             */
            'statement_closing_date' => $rules['statement_closing_date'] ?? ['exclude'],
            /**
             * `credit_card` only: when that statement is charged. On or after the closing date, at most 45 days later on a monthly card and 7 on a weekly one.
             *
             * @format date
             */
            'payment_due_date' => $rules['payment_due_date'] ?? ['exclude'],
            /**
             * `credit_card` only: how often a statement closes. Left out, the stored one stays (monthly on a new card).
             *
             * @var 'monthly'|'weekly'|null
             */
            'statement_frequency' => $rules['statement_frequency'] ?? ['exclude'],
            /**
             * `credit_card` only: the card's credit limit, in minor units. `null` clears it.
             */
            'credit_limit' => $rules['credit_limit'] ?? ['exclude'],
        ];
    }
}
