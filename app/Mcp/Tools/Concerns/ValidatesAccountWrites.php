<?php

namespace App\Mcp\Tools\Concerns;

use App\Enums\AccountType;
use App\Enums\PropertyType;
use App\Http\Requests\Concerns\ValidatesAccountDetailRules;
use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use App\Models\User;
use App\Rules\PaymentDueAfterStatementClosing;
use App\Services\CreditCards\CreditCardStatementService;
use App\Services\CurrencyOptions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;

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
            AccountType::CreditCard => $this->creditCardDetailRules(),
            default => [],
        };
    }

    /**
     * The credit card fields sit behind a per-user flag, while the schema that
     * offers them is the same for everyone. A user without the flag is told
     * so instead of having them silently dropped.
     */
    protected function refuseUnavailableCreditCardFields(Request $request, User $user): void
    {
        if (app(CreditCardStatementService::class)->isAvailableTo($user)) {
            return;
        }

        foreach (['statement_closing_date', 'payment_due_date', 'credit_limit'] as $field) {
            if ($request->has($field)) {
                throw ValidationException::withMessages([
                    $field => 'Credit card limits and statement dates are not available for this account yet. Leave statement_closing_date, payment_due_date and credit_limit out.',
                ]);
            }
        }
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
     * The real estate, loan and credit card detail fields, offered by both tools so an
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
            'statement_closing_date' => $schema->string()->description('credit_card only: a statement closing date, YYYY-MM-DD. Later cycles repeat monthly from it. Send with payment_due_date; both null clears them.'),
            'payment_due_date' => $schema->string()->description('credit_card only: when that statement is charged, YYYY-MM-DD. After the closing date, at most '.PaymentDueAfterStatementClosing::MAX_DAYS_TO_PAY.' days later.'),
            'credit_limit' => $schema->integer()->description('credit_card only: the card\'s credit limit, in minor units. null clears it.'),
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
