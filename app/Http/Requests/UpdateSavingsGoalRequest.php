<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesMonthlySavingsTarget;
use App\Models\SavingsGoal;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSavingsGoalRequest extends FormRequest
{
    use ValidatesMonthlySavingsTarget;

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
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('labels', 'name')
                    ->where('user_id', auth()->id())
                    ->whereNull('deleted_at')
                    ->ignore($this->goal()?->label_id),
            ],
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

        return [
            ...$rules,
            'target_amount' => ['sometimes', 'required', 'integer', 'min:1'],
            'initial_amount' => ['sometimes', 'required', 'integer', 'min:0'],
            // Pin the format and bound the year for the same reason as in
            // StoreSavingsGoalRequest: a five-digit year typo passes 'date' and
            // then blows up as an out-of-range MySQL date (PHP-LARAVEL-5X). There
            // is no `after:today` here on purpose — a goal whose target date has
            // already passed still has to be editable — so 1900 is the floor that
            // keeps a dropped-digit typo like 0026-11-10 out.
            'target_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2100-01-01'],
        ];
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
