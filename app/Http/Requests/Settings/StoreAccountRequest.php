<?php

namespace App\Http\Requests\Settings;

use App\Enums\AccountType;
use App\Enums\PropertyType;
use App\Models\Account;
use App\Services\CurrencyOptions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
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
            $rules = array_merge($rules, [
                'property_type' => [
                    'required',
                    'string',
                    Rule::in(array_map(fn ($type) => $type->value, PropertyType::cases())),
                ],
                'address' => ['nullable', 'string', 'max:500'],
                'purchase_price' => ['nullable', 'integer', 'min:0'],
                'purchase_date' => ['nullable', 'date', 'before_or_equal:today'],
                'area_value' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
                'area_unit' => ['nullable', 'string', Rule::in(['sqm', 'sqft', 'acres', 'hectares'])],
                'linked_loan_account_id' => [
                    'nullable',
                    'string',
                    Rule::exists('accounts', 'id')->where(function ($query) {
                        $query->where('user_id', $this->user()->id)
                            ->where('type', AccountType::Loan->value);
                    }),
                ],
                'notes' => ['nullable', 'string', 'max:2000'],
                'revaluation_percentage' => ['nullable', 'numeric', 'min:-100', 'max:100'],
            ]);
        }

        $isLoan = $this->input('type') === AccountType::Loan->value;

        if ($isLoan) {
            $rules = array_merge($rules, [
                'annual_interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'loan_term_months' => ['nullable', 'integer', 'min:1', 'max:600'],
                'loan_start_date' => ['nullable', 'date'],
                'original_amount' => ['nullable', 'integer', 'min:0'],
                'linked_real_estate_account_id' => [
                    'nullable',
                    'string',
                    Rule::exists('accounts', 'id')->where(function ($query) {
                        $query->where('user_id', $this->user()->id)
                            ->where('type', AccountType::RealEstate->value);
                    }),
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
