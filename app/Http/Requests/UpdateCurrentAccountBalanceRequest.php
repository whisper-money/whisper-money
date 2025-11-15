<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCurrentAccountBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'balance' => ['required', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'balance.required' => 'The balance is required.',
            'balance.integer' => 'The balance must be an integer.',
        ];
    }
}
