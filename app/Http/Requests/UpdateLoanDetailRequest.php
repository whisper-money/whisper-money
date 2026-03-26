<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLoanDetailRequest extends FormRequest
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
            'annual_interest_rate' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'loan_term_months' => ['sometimes', 'required', 'integer', 'min:1', 'max:600'],
            'start_date' => ['sometimes', 'required', 'date'],
            'original_amount' => ['sometimes', 'required', 'integer', 'min:0'],
        ];
    }
}
