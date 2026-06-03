<?php

namespace App\Http\Requests\Settings;

use App\Enums\AccountType;
use App\Http\Requests\Concerns\ValidatesAccountDetailRules;
use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
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
        $currencyOptions = app(CurrencyOptions::class);

        $rules = [
            'name' => ['required', 'string'],
            'bank_id' => ['nullable', 'exists:banks,id'],
            'currency_code' => [
                'required',
                'string',
                Rule::in($currencyOptions->accountCodes()),
            ],
            'type' => [
                'required',
                'string',
                Rule::in(array_map(fn ($type) => $type->value, AccountType::cases())),
            ],
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
}
