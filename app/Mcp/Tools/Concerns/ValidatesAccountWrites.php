<?php

namespace App\Mcp\Tools\Concerns;

use App\Enums\AccountType;
use App\Enums\PropertyType;
use App\Http\Requests\Concerns\ValidatesAccountDetailRules;
use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use App\Models\User;
use App\Services\CurrencyOptions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;

/**
 * The account write rules and schema fields, shared by create_account and
 * update_account.
 *
 * The per-type detail rules are the settings form's own
 * (ValidatesAccountDetailRules), reused rather than restated so the two write
 * paths cannot drift apart. Those traits build rules against `$this->user()`,
 * which a form request has and a tool does not, so the user is handed in and
 * served from here.
 */
trait ValidatesAccountWrites
{
    use ValidatesAccountDetailRules, ValidatesUserOwnedResources;

    private User $rulesUser;

    /**
     * Hand the reused form-request rules the user they build themselves
     * against. Called before any rule is built, since they reach for it
     * through user().
     */
    protected function buildRulesFor(User $user): void
    {
        $this->rulesUser = $user;
    }

    /**
     * The detail rules for the type being written. Empty for the types that
     * carry no detail of their own.
     *
     * @return array<string, array<mixed>>
     */
    protected function detailRules(?AccountType $type, bool $creating): array
    {
        return match ($type) {
            // An update changes only the fields it is passed, so the property
            // type is only demanded while the detail row is being created.
            AccountType::RealEstate => $this->realEstateDetailRules(propertyTypeSometimes: ! $creating),
            AccountType::Loan => [
                ...$this->loanDetailRules(),
                // A mortgage is pointed at its property when it is opened;
                // after that the link lives on the property's own detail.
                ...$creating ? $this->linkedRealEstateAccountRules() : [],
            ],
            default => [],
        };
    }

    /**
     * The currency codes an account may be opened in. A user with no account
     * yet is picking the currency their whole account will be reported in, so
     * only the primary ones are offered.
     *
     * @return list<string>
     */
    protected function allowedCurrencyCodes(User $user): array
    {
        $currencyOptions = app(CurrencyOptions::class);

        return $user->accounts()->exists()
            ? $currencyOptions->accountCodes()
            : $currencyOptions->primaryCodes();
    }

    /**
     * @return array<string, array<mixed>>
     */
    protected function typeRule(bool $required): array
    {
        return [
            'type' => [
                $required ? 'required' : 'sometimes',
                'string',
                Rule::in(array_column(AccountType::cases(), 'value')),
            ],
        ];
    }

    /**
     * The real estate and loan detail fields, offered by both tools so an
     * account of either type can be described in one call.
     *
     * @return array<string, mixed>
     */
    protected function detailSchema(JsonSchema $schema): array
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
        ];
    }

    /**
     * The user the reused form-request rules build themselves against.
     */
    protected function user(): User
    {
        return $this->rulesUser;
    }
}
