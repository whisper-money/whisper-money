<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NamesSavingsGoalAttributes;
use App\Http\Requests\Concerns\ValidatesMonthlySavingsTarget;
use App\Http\Requests\Concerns\ValidatesOneOffSavingsTarget;
use App\Http\Requests\Concerns\ValidatesSavingsGoalName;
use App\Models\SavingsGoal;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSavingsGoalRequest extends FormRequest
{
    use NamesSavingsGoalAttributes, ValidatesMonthlySavingsTarget, ValidatesOneOffSavingsTarget, ValidatesSavingsGoalName;

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
            'name' => $this->savingsGoalNameRules((string) auth()->id(), creating: false, ownLabelId: $this->goal()?->label_id),
            // A goal keeps the kind it was created with: its months and its
            // one-off progress are two different histories, and neither turns
            // into the other.
            'kind' => ['sometimes', function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== $this->goal()?->kind->value) {
                    $fail(__('A savings goal can\'t switch between one-off and monthly.'));
                }
            }],
        ];

        if ($this->goal()?->isMonthly()) {
            return [...$rules, ...$this->monthlyTargetRules(creating: false)];
        }

        return [...$rules, ...$this->oneOffTargetRules(creating: false)];
    }

    public function goal(): ?SavingsGoal
    {
        $goal = $this->route('savingsGoal');

        return $goal instanceof SavingsGoal ? $goal : null;
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
