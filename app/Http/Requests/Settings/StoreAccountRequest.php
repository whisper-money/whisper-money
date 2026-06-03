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
        ];

        if ($isRealEstate) {
            $rules = array_merge($rules, $this->realEstateDetailRules());
        }

        $isLoan = $this->input('type') === AccountType::Loan->value;

        if ($isLoan) {
            $rules = array_merge($rules, $this->loanDetailRules(), [
                'linked_real_estate_account_id' => [
                    'nullable',
                    'string',
                    $this->userOwnedAccountOfType(AccountType::RealEstate),
                    function (string $attribute, mixed $value, \Closure $fail): void {
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
            ]);
        }

        return $rules;
    }
}
