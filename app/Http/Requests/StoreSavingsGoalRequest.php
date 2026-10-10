<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Enums\SavingsGoalKind;
use App\Http\Requests\Concerns\ValidatesMonthlySavingsTarget;
use App\Http\Requests\Concerns\ValidatesOneOffSavingsTarget;
use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSavingsGoalRequest extends FormRequest
{
    use ValidatesMonthlySavingsTarget, ValidatesOneOffSavingsTarget, ValidatesUserOwnedResources;

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
                ...$this->monthlyTargetRules(creating: true),
                // Only a savings account: on any other type an incoming transfer
                // counts against the goal, not towards it. And one of the space
                // the goal is created in, where its rule and label live.
                'auto_tag_account_id' => [
                    'nullable',
                    'uuid',
                    $this->userOwnedAccountOfType(AccountType::Savings)->where('space_id', $this->user()->activeSpace()->id),
                ],
            ];
        }

        return [...$rules, ...$this->oneOffTargetRules(creating: true)];
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
