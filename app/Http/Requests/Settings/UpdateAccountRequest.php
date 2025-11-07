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
        return [
            'name' => ['required', 'string'],
            'name_iv' => ['required', 'string', 'size:16'],
            'bank_id' => ['required', 'exists:banks,id'],
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
    }
}
