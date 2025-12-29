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
        $userId = $this->user()->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'period_type' => ['sometimes', Rule::enum(BudgetPeriodType::class)],
            'period_duration' => ['nullable', 'integer', 'min:1', 'max:365'],
            'period_start_day' => ['nullable', 'integer', 'min:0', 'max:31'],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('user_id', $userId)],
            'label_id' => ['nullable', Rule::exists('labels', 'id')->where('user_id', $userId)],
            'rollover_type' => ['sometimes', Rule::enum(RolloverType::class)],
            'allocated_amount' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
