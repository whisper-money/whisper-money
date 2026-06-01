<?php

namespace App\Http\Requests;

use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
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
            'period_start_day' => ['nullable', 'integer', 'min:0', 'max:31'],
            'rollover_type' => ['sometimes', Rule::enum(RolloverType::class)],
            'allocated_amount' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
