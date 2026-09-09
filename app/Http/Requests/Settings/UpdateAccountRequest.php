<?php

namespace App\Http\Requests\Settings;

use App\Enums\AccountType;
use App\Http\Requests\Concerns\ValidatesAccountDetailRules;
use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use App\Models\Account;
use App\Services\CurrencyOptions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
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

        $rules = [
            'name' => ['required', 'string'],
            'bank_id' => ['nullable', 'exists:banks,id'],
            'currency_code' => [
                'required',
                'string',
                Rule::in($this->allowedCurrencyCodes()),
            ],
            'type' => [
                'required',
                'string',
                Rule::in(array_map(fn ($type) => $type->value, AccountType::cases())),
            ],
            'ownership_percentage' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'ownership_applies_to_balance' => ['sometimes', 'boolean'],
        ];

        if ($isRealEstate) {
            $rules = array_merge($rules, $this->realEstateDetailRules());
        }

        $isLoan = $this->input('type') === AccountType::Loan->value;

        if ($isLoan) {
            $rules = array_merge($rules, $this->loanDetailRules());
        }

        return $rules;
    }

    /**
     * The bank owns the currency of a connected account: everything already
     * synced is in it, so only the code it already has is accepted. A manual
     * account can move to any supported currency.
     *
     * @return list<string>
     */
    private function allowedCurrencyCodes(): array
    {
        $account = $this->route('account');

        if ($account instanceof Account && $account->isConnected()) {
            return [$account->currency_code];
        }

        return app(CurrencyOptions::class)->accountCodes();
    }
}
