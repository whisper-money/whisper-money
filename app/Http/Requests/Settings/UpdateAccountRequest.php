<?php

namespace App\Http\Requests\Settings;

use App\Enums\AccountType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
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

        $rules = [
            'name' => ['required', 'string'],
            'bank_id' => $isRealEstate
                ? ['nullable', 'exists:banks,id']
                : ['required', 'exists:banks,id'],
            'currency_code' => [
                'required',
                'string',
                Rule::in(['USD', 'EUR', 'GBP', 'JPY', 'CHF', 'CAD', 'AUD', 'CNY', 'INR', 'MXN']),
            ],
            'type' => [
                'required',
                'string',
                Rule::in(array_map(fn ($type) => $type->value, AccountType::cases())),
            ],
        ];

        $isLoan = $this->input('type') === AccountType::Loan->value;

        if ($isLoan) {
            $rules = array_merge($rules, [
                'annual_interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'loan_term_months' => ['nullable', 'integer', 'min:1', 'max:600'],
                'loan_start_date' => ['nullable', 'date'],
                'original_amount' => ['nullable', 'integer', 'min:0'],
            ]);
        }

        return $rules;
    }
}
