<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IndexEstimateTransactionsRequest extends FormRequest
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
            // The months a file about to be imported covers.
            'months' => ['required', 'array', 'max:240'],
            'months.*' => ['required', 'date_format:Y-m'],
        ];
    }
}
