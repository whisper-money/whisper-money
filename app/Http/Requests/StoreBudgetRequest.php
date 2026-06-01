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
        $userId = $this->user()->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'period_type' => ['required', Rule::enum(BudgetPeriodType::class)],
            'period_start_day' => ['nullable', 'integer', 'min:0', 'max:31'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => [Rule::exists('categories', 'id')->where('user_id', $userId)],
            'label_ids' => ['nullable', 'array'],
            'label_ids.*' => [Rule::exists('labels', 'id')->where('user_id', $userId)],
            'rollover_type' => ['required', Rule::enum(RolloverType::class)],
            'allocated_amount' => ['required', 'integer', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $hasCategories = ! empty($this->category_ids);
            $hasLabels = ! empty($this->label_ids);

            if (! $hasCategories && ! $hasLabels) {
                $validator->errors()->add(
                    'selection',
                    'You must select at least one category or label.'
                );
            }
        });
    }
}
