<?php

namespace App\Services\Accounts;

use App\Enums\AccountType;
use App\Http\Requests\Concerns\ValidatesAccountDetailRules;
use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use App\Models\Account;
use App\Models\CreditCardDetail;
use App\Models\User;
use App\Services\CurrencyOptions;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The account write rules and refusals, shared by the create_account and
 * update_account MCP tools and the `/api/v1/accounts` form requests, so the
 * two write paths cannot drift apart.
 *
 * The per-type detail rules are the settings form's own
 * (ValidatesAccountDetailRules), reused rather than restated. Those traits
 * build rules against `$this->user()`, so the user is handed in and served
 * from here. It may be null only while the API reference is generated, which
 * builds the rules without a request user.
 *
 * The refusals return their errors keyed by field instead of throwing, so a
 * tool can throw them one at a time and a form request can add them to its
 * validator.
 */
class AccountWriteRules
{
    use ValidatesAccountDetailRules, ValidatesUserOwnedResources;

    /**
     * Fields that would point a new account at a bank connection. Only the
     * provider consent flow in the app may do that, so a caller reaching for
     * one is told where the real thing lives instead of getting an account
     * that looks connected and never syncs.
     *
     * @var list<string>
     */
    private const array ConnectionFields = ['banking_connection_id', 'external_account_id', 'is_connected'];

    public function __construct(private ?User $rulesUser) {}

    /**
     * @return array<string, array<mixed>>
     */
    public function typeRule(bool $required): array
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
     * The rules for a new account of $type, besides the type itself.
     *
     * @return array<string, array<mixed>>
     */
    public function createRules(?AccountType $type): array
    {
        return [
            'name' => ['required', 'string'],
            'bank_id' => ['nullable', 'exists:banks,id'],
            'currency_code' => ['required', 'string', Rule::in($this->allowedCurrencyCodes())],
            'balance' => ['nullable', 'integer'],
            ...$this->detailRules($type, creating: true),
        ];
    }

    /**
     * The rules for an edit that leaves the account of $type, besides the
     * type itself. Every field is optional: only what is sent changes.
     *
     * @return array<string, array<mixed>>
     */
    public function updateRules(?AccountType $type, ?CreditCardDetail $storedCreditCard): array
    {
        return [
            'name' => ['sometimes', 'string'],
            'bank_id' => ['nullable', 'exists:banks,id'],
            'currency_code' => ['sometimes', 'string', Rule::in($this->allowedCurrencyCodes())],
            'ownership_percentage' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'ownership_applies_to_balance' => ['sometimes', 'boolean'],
            ...$this->detailRules($type, creating: false, storedCreditCard: $storedCreditCard),
        ];
    }

    /**
     * A bank-connected account cannot be created by hand: it is born from the
     * bank's consent flow. Only the first offending field is reported.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function connectionFieldErrors(array $input): array
    {
        foreach (self::ConnectionFields as $field) {
            if (array_key_exists($field, $input)) {
                return [$field => 'A bank-connected account cannot be created from here: it is born from the bank\'s consent flow, which only the user can go through in the Whisper Money app. Create a manual account instead, or ask the user to connect the bank themselves.'];
            }
        }

        return [];
    }

    /**
     * A credit card has no balance: what is in use of it comes from its
     * transactions. Telling the caller beats storing a figure nothing reads.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function creditCardBalanceErrors(array $input, ?AccountType $type): array
    {
        if (! array_key_exists('balance', $input) || $type === null || $type->hasBalance()) {
            return [];
        }

        return ['balance' => 'A credit card has no balance: leave balance out and send credit_limit instead. What is in use of the card is worked out from its transactions.'];
    }

    /**
     * The fields the bank sync owns on a connected account. The sync never
     * rewrites a name, so renaming one is safe and sticks — but its currency
     * is what its whole synced history is denominated in, its bank comes from
     * the connection, and a type with no transaction ledger leaves the sync
     * nowhere to write.
     *
     * Only a type the caller actually asked for is judged: an account that is
     * already of some other type stays editable in every other respect. Only
     * the first offending field is reported.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function syncOwnedFieldErrors(Account $account, array $input, ?AccountType $requestedType): array
    {
        if (! $account->isConnected()) {
            return [];
        }

        if (array_key_exists('currency_code', $input)) {
            return ['currency_code' => 'That account is connected to a bank, so its currency came from the bank and every synced transaction is denominated in it. Changing it would reinterpret the whole history.'];
        }

        if (array_key_exists('bank_id', $input)) {
            return ['bank_id' => 'That account is connected to a bank, so its bank comes from the connection. To move it to another bank the user connects that bank in the Whisper Money app.'];
        }

        if ($requestedType !== null && ! $requestedType->canSyncBankTransactions()) {
            return ['type' => "That account is connected to a bank, so its type has to keep a transaction ledger: only checking, credit_card, savings and others do. A balance-only type like {$requestedType->value} would leave the sync nowhere to write, and it would silently stop."];
        }

        return [];
    }

    /**
     * The loan fields AccountWriteService::update() reported missing, worded
     * for the caller.
     *
     * @param  list<string>  $missingLoanFields
     * @return array<string, string>
     */
    public static function missingLoanFieldErrors(array $missingLoanFields): array
    {
        return array_fill_keys(
            $missingLoanFields,
            'This account has no loan details yet, so annual_interest_rate, loan_term_months and original_amount are all needed to create them. The loan details were left alone; any other field in the same call was saved.',
        );
    }

    /**
     * @param  array<string, string>  $errors
     *
     * @throws ValidationException
     */
    public static function throwIfAny(array $errors): void
    {
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The detail rules for the type being written. Empty for the types that
     * carry no detail of their own. With no type yet (the type is invalid,
     * or the API reference is being generated) every type's detail rules are
     * offered, none of them demanded.
     *
     * @return array<string, array<mixed>>
     */
    private function detailRules(?AccountType $type, bool $creating, ?CreditCardDetail $storedCreditCard = null): array
    {
        return match ($type) {
            null => [
                ...$this->realEstateDetailRules(propertyTypeSometimes: true),
                ...$this->loanDetailRules(),
                ...$creating ? $this->linkedRealEstateAccountRules() : [],
                ...$this->creditCardDetailRules($storedCreditCard),
            ],
            // An update changes only the fields it is passed, so the property
            // type is only demanded while the detail row is being created.
            AccountType::RealEstate => $this->realEstateDetailRules(propertyTypeSometimes: ! $creating),
            AccountType::Loan => [
                ...$this->loanDetailRules(),
                // A mortgage is pointed at its property when it is opened;
                // after that the link lives on the property's own detail.
                ...$creating ? $this->linkedRealEstateAccountRules() : [],
            ],
            AccountType::CreditCard => $this->creditCardDetailRules($storedCreditCard),
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
    private function allowedCurrencyCodes(): array
    {
        $currencyOptions = app(CurrencyOptions::class);

        return $this->rulesUser?->accounts()->exists() === false
            ? $currencyOptions->primaryCodes()
            : $currencyOptions->accountCodes();
    }

    /**
     * The user the reused form-request rules build themselves against.
     */
    private function user(): ?User
    {
        return $this->rulesUser;
    }
}
