<?php

namespace App\Http\Requests\Settings;

use App\Enums\Locale;
use App\Models\User;
use App\Services\CurrencyOptions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $currencyOptions = app(CurrencyOptions::class);

        return [
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            'currency_code' => ['required', 'string', 'max:3', Rule::in($currencyOptions->primaryCodes())],
            'locale' => ['nullable', 'string', Rule::enum(Locale::class)],
        ];
    }
}
