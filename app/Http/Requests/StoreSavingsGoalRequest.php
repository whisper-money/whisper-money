<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Enums\SavingsGoalKind;
use App\Http\Requests\Concerns\ValidatesMonthlySavingsTarget;
use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSavingsGoalRequest extends FormRequest
{
    use ValidatesMonthlySavingsTarget, ValidatesUserOwnedResources;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('labels', 'name')
                    ->where('user_id', auth()->id())
                    ->whereNull('deleted_at'),
            ],
            'kind' => ['sometimes', Rule::enum(SavingsGoalKind::class)],
        ];

        if ($this->isMonthly()) {
            return [
                ...$rules,
                ...$this->monthlyTargetRules('required'),
                // Only a savings account: on any other type an incoming transfer
                // counts against the goal, not towards it.
                'auto_tag_account_id' => ['nullable', 'uuid', $this->userOwnedAccountOfType(AccountType::Savings)],
            ];
        }

        return [
            ...$rules,
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

    public function isMonthly(): bool
    {
        return $this->input('kind') === SavingsGoalKind::Monthly->value;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => __('You already have a label or goal with this name.'),
            'target_date.date_format' => __('Please enter a valid target date.'),
        ];
    }
}
