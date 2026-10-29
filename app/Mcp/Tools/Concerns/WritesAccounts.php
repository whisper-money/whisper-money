<?php

namespace App\Mcp\Tools\Concerns;

use App\Enums\PropertyType;
use App\Enums\StatementFrequency;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * What create_account and update_account share: the account row they answer
 * with and the schema fields they both take. Their rules live in
 * AccountWriteRules, which the `/api/v1/accounts` form requests share.
 */
trait WritesAccounts
{
    use PresentsAccounts;

    /**
     * The real estate, loan and credit card detail fields, offered by both tools so an
     * account of either type can be described in one call, and the space.
     *
     * @return array<string, mixed>
     */
    protected function sharedSchema(JsonSchema $schema): array
    {
        return [
            'property_type' => $schema->string()->enum(array_column(PropertyType::cases(), 'value'))->description('real_estate only: what kind of property it is.'),
            'address' => $schema->string()->description('real_estate only: the property address.'),
            'purchase_price' => $schema->integer()->description('real_estate only: what it was bought for, in minor units. With purchase_date and balance, the value history in between is filled in.'),
            'purchase_date' => $schema->string()->description('real_estate only: purchase date, YYYY-MM-DD. Not in the future, not before 1900.'),
            'area_value' => $schema->number()->description('real_estate only: floor area.'),
            'area_unit' => $schema->string()->enum(['sqm', 'sqft', 'acres', 'hectares'])->description('real_estate only: unit of area_value.'),
            'revaluation_percentage' => $schema->number()->description('real_estate only: expected yearly revaluation, -100 to 100.'),
            'notes' => $schema->string()->description('real_estate only: free-text notes on the property.'),
            'linked_loan_account_id' => $schema->string()->description('real_estate only: id of the loan account that is this property\'s mortgage.'),
            'annual_interest_rate' => $schema->number()->description('loan only: yearly interest rate as a percentage, 0 to 100.'),
            'loan_term_months' => $schema->integer()->description('loan only: term in months, 1 to 600.'),
            'original_amount' => $schema->integer()->description('loan only: amount originally borrowed, in minor units.'),
            'loan_start_date' => $schema->string()->description('loan only: when the loan started, YYYY-MM-DD. Defaults to today.'),
            'statement_closing_date' => $schema->string()->description('credit_card only: a statement closing date, YYYY-MM-DD. Later cycles repeat from it at statement_frequency. Send with payment_due_date; both null clears them.'),
            'payment_due_date' => $schema->string()->description('credit_card only: when that statement is charged, YYYY-MM-DD. On or after the closing date, at most '.StatementFrequency::Monthly->maxDaysToPay().' days later on a monthly card and '.StatementFrequency::Weekly->maxDaysToPay().' on a weekly one.'),
            'statement_frequency' => $schema->string()->enum(array_column(StatementFrequency::cases(), 'value'))->description('credit_card only: how often a statement closes, monthly (the default) or weekly. Left out, the stored one stays. Kept only on a card with statement dates or a credit limit.'),
            'credit_limit' => $schema->integer()->description('credit_card only: the card\'s credit limit, in minor units. null clears it.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }
}
