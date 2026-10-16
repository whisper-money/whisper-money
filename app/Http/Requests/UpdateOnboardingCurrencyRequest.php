<?php

namespace App\Http\Requests;

use App\Services\CurrencyOptions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOnboardingCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The same closed list the profile form offers, since this is the same
     * column and Settings is where the user is told they can change it later.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'currency_code' => ['required', 'string', Rule::in(app(CurrencyOptions::class)->primaryCodes())],
        ];
    }
}
