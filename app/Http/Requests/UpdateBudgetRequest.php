<?php

namespace App\Http\Requests;

use App\Enums\BudgetExpenseType;
use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use App\Models\Budget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'period_type' => ['sometimes', Rule::enum(BudgetPeriodType::class)],
            'period_start_day' => ['nullable', 'integer', ...$this->periodType()->startDayRules()],
            'rollover_type' => ['sometimes', Rule::enum(RolloverType::class)],
            'expense_type' => ['sometimes', Rule::enum(BudgetExpenseType::class)],
            'allocated_amount' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * The fields the budget itself takes. A catch-all budget keeps the default
     * expense type, since it has no kind of spending of its own.
     *
     * @return array<string, mixed>
     */
    public function budgetAttributes(): array
    {
        $budget = $this->route('budget');
        $fields = ['name', 'period_type', 'period_start_day', 'rollover_type'];

        if (! ($budget instanceof Budget && $budget->is_catch_all)) {
            $fields[] = 'expense_type';
        }

        return $this->only($fields);
    }

    /**
     * A PATCH may change the period type, keep it, or not mention it, so the
     * range for the start day comes from whichever type the budget will end up
     * with.
     */
    private function periodType(): BudgetPeriodType
    {
        $budget = $this->route('budget');

        return BudgetPeriodType::tryFrom((string) $this->input('period_type'))
            ?? ($budget instanceof Budget ? $budget->period_type : BudgetPeriodType::Monthly);
    }
}
