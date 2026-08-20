<?php

namespace App\Http\Requests;

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
            'allocated_amount' => ['sometimes', 'integer', 'min:0'],
        ];
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
