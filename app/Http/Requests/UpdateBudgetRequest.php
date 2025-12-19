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
            'period_duration' => ['nullable', 'integer', 'min:1', 'max:365'],
            'period_start_day' => ['nullable', 'integer', 'min:0', 'max:31'],
            'categories' => ['sometimes', 'array'],
            'categories.*.category_id' => ['required', 'exists:categories,id'],
            'categories.*.rollover_type' => ['required', Rule::enum(RolloverType::class)],
            'categories.*.label_ids' => ['nullable', 'array'],
            'categories.*.label_ids.*' => ['exists:labels,id'],
        ];
    }
}
