<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Enums\PropertyType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRealEstateDetailRequest extends FormRequest
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
            'property_type' => [
                'sometimes',
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
        ];
    }
}
