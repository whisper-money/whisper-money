<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Enums\SavingsGoalKind;
use App\Http\Requests\Concerns\NamesSavingsGoalAttributes;
use App\Http\Requests\Concerns\ValidatesMonthlySavingsTarget;
use App\Http\Requests\Concerns\ValidatesOneOffSavingsTarget;
use App\Http\Requests\Concerns\ValidatesSavingsGoalName;
use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use App\Models\SavingsGoal;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSavingsGoalRequest extends FormRequest
{
    use NamesSavingsGoalAttributes, ValidatesMonthlySavingsTarget, ValidatesOneOffSavingsTarget, ValidatesSavingsGoalName, ValidatesUserOwnedResources;

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
            'name' => $this->savingsGoalNameRules((string) auth()->id(), creating: true),
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
                    // Rules stop at the first match: a second goal on the
                    // same account would never see a transfer.
                    function (string $attribute, mixed $value, Closure $fail): void {
                        $owner = is_string($value) ? SavingsGoal::autoTaggingAccount($value) : null;

                        if ($owner !== null) {
                            $fail(__('This account already feeds “:goal”. Pick another one, or leave contributions to be linked by hand.', ['goal' => $owner->name]));
                        }
                    },
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
            'auto_tag_account_id.exists' => __('Pick one of your savings accounts.'),
            'target_date.date_format' => __('Please enter a valid target date.'),
        ];
    }
}
