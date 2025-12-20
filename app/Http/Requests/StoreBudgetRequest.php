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
            'category_id' => ['nullable', 'exists:categories,id'],
            'label_id' => ['nullable', 'exists:labels,id'],
            'rollover_type' => ['required', Rule::enum(RolloverType::class)],
            'allocated_amount' => ['required', 'integer', 'min:0'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $hasCategoryId = ! empty($this->category_id);
            $hasLabelId = ! empty($this->label_id);

            if (! $hasCategoryId && ! $hasLabelId) {
                $validator->errors()->add(
                    'selection',
                    'You must select either a category or a label.'
                );
            }
        });
    }
}
