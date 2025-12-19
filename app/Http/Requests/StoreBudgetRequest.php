<?php

namespace App\Http\Requests;

use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'period_type' => ['required', Rule::enum(BudgetPeriodType::class)],
            'period_duration' => ['nullable', 'integer', 'min:1', 'max:365'],
            'period_start_day' => ['nullable', 'integer', 'min:0', 'max:31'],
            'categories' => ['required', 'array', 'min:1'],
            'categories.*.category_id' => ['required', 'exists:categories,id'],
            'categories.*.rollover_type' => ['required', Rule::enum(RolloverType::class)],
            'categories.*.allocated_amount' => ['required', 'integer', 'min:0'],
            'categories.*.label_ids' => ['nullable', 'array'],
            'categories.*.label_ids.*' => ['exists:labels,id'],
        ];
    }
}
