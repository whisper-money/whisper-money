<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => ['sometimes', 'uuid'],
            'account_id' => [
                'required',
                Rule::exists('accounts', 'id')->where(function ($query) {
                    $query->where('user_id', $this->user()->id);
                }),
            ],
            'balance_date' => ['required', 'date'],
            'balance' => ['required', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'account_id.required' => 'The account is required.',
            'account_id.exists' => 'The selected account does not exist.',
            'balance_date.required' => 'The balance date is required.',
            'balance_date.date' => 'The balance date must be a valid date.',
            'balance.required' => 'The balance is required.',
            'balance.integer' => 'The balance must be an integer.',
        ];
    }
}
