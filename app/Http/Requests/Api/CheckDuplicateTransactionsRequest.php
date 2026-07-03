<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CheckDuplicateTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'account_id' => ['required', 'uuid'],
            'transactions' => ['required', 'array', 'max:10000'],
            'transactions.*.transaction_date' => ['required', 'date'],
            'transactions.*.amount' => ['required', 'integer'],
            'transactions.*.description' => ['required', 'string'],
        ];
    }
}
