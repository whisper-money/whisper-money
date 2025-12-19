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
            'categories.*.category_id' => ['nullable', 'exists:categories,id'],
            'categories.*.rollover_type' => ['required', Rule::enum(RolloverType::class)],
            'categories.*.allocated_amount' => ['required', 'integer', 'min:0'],
            'categories.*.label_ids' => ['nullable', 'array'],
            'categories.*.label_ids.*' => ['required', 'exists:labels,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'categories.*.category_id.exists' => 'The selected category is invalid.',
            'categories.*.label_ids.*.exists' => 'The selected label is invalid.',
            'categories.*.label_ids.*.required' => 'Label IDs cannot be empty.',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            foreach ($this->categories ?? [] as $index => $category) {
                $hasCategoryId = !empty($category['category_id']);
                $hasLabelIds = !empty($category['label_ids']) && count(array_filter($category['label_ids'])) > 0;

                if (!$hasCategoryId && !$hasLabelIds) {
                    $validator->errors()->add(
                        "categories.{$index}",
                        'You must select at least a category or a label.'
                    );
                }
            }
        });
    }
}
