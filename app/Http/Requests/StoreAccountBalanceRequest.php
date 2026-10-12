<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RefusesCreditCardBalance;
use Illuminate\Foundation\Http\FormRequest;

class StoreAccountBalanceRequest extends FormRequest
{
    use RefusesCreditCardBalance;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'balance_date' => ['required', 'date'],
            'balance' => ['required', 'integer'],
            'invested_amount' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'balance_date.required' => 'The balance date is required.',
            'balance_date.date' => 'The balance date must be a valid date.',
            'balance.required' => 'The balance is required.',
            'balance.integer' => 'The balance must be an integer.',
        ];
    }
}
