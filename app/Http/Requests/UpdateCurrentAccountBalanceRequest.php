<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RequiresAccountWithBalance;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCurrentAccountBalanceRequest extends FormRequest
{
    use RequiresAccountWithBalance;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'balance' => ['required', 'integer'],
            'invested_amount' => ['nullable', 'integer'],
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
