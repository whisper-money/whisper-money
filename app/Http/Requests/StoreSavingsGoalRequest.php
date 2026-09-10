<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSavingsGoalRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('labels', 'name')
                    ->where('user_id', auth()->id())
                    ->whereNull('deleted_at'),
            ],
            'target_amount' => ['required', 'integer', 'min:1'],
            'initial_amount' => ['nullable', 'integer', 'min:0'],
            // Pin the format and cap the year: 'date' alone silently mangles a
            // five-digit year typo like 20026-11-10 into 2006-11-10, so every
            // range rule sees a plausible date while the raw string is what
            // reaches MySQL and blows up as an out-of-range date (PHP-LARAVEL-5X).
            // The date picker always sends Y-m-d, and 2100 rejects typos rather
            // than real target dates.
            'target_date' => ['nullable', 'date_format:Y-m-d', 'after:today', 'before_or_equal:2100-01-01'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => __('You already have a label or goal with this name.'),
        ];
    }
}
