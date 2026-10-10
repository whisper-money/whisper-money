<?php

namespace App\Http\Requests\Settings;

use App\Enums\AccountType;
use App\Http\Requests\Concerns\ValidatesAccountDetailRules;
use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use App\Services\CreditCards\CreditCardStatementService;
use App\Services\CurrencyOptions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    use ValidatesAccountDetailRules, ValidatesUserOwnedResources;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isRealEstate = $this->input('type') === AccountType::RealEstate->value;
        $currencyOptions = app(CurrencyOptions::class);
        $allowedCurrencyCodes = $this->user()->accounts()->exists()
            ? $currencyOptions->accountCodes()
            : $currencyOptions->primaryCodes();

        $rules = [
            'name' => ['required', 'string'],
            'bank_id' => ['nullable', 'exists:banks,id'],
            'currency_code' => [
                'required',
                'string',
                Rule::in($allowedCurrencyCodes),
            ],
            'type' => [
                'required',
                'string',
                Rule::in(array_map(fn ($type) => $type->value, AccountType::cases())),
            ],
            'balance' => ['nullable', 'integer'],
            'invested_amount' => ['nullable', 'integer'],
        ];

        if ($isRealEstate) {
            $rules = array_merge($rules, $this->realEstateDetailRules());
        }

        $isLoan = $this->input('type') === AccountType::Loan->value;

        if ($isLoan) {
            $rules = array_merge(
                $rules,
                // A brand new account has no detail row to update, so the loan
                // fields are all or nothing.
                $this->loanDetailRules(requireCompleteSet: true),
                $this->linkedRealEstateAccountRules(),
            );
        }

        // The limit is offered only to users with the credit card feature;
        // anyone else sending one has it left out of the validated data. For
        // them a card has a limit instead of a balance, so a balance sent
        // along is left out the same way.
        $type = AccountType::tryFrom((string) $this->input('type'));

        if ($type !== null && app(CreditCardStatementService::class)->opensWithoutBalance($this->user(), $type)) {
            $rules = array_merge($rules, ['balance' => ['exclude']], $this->creditLimitRules());
        }

        return $rules;
    }
}
