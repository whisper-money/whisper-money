<?php

namespace App\Http\Requests\Settings;

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryColor;
use App\Enums\CategoryType;
use App\Features\CashflowSavingsInvestmentsAndPeriods;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;

class StoreCategoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('type') !== CategoryType::Transfer->value) {
            $this->merge([
                'cashflow_direction' => CategoryCashflowDirection::Hidden->value,
            ]);

            return;
        }

        $this->merge([
            'cashflow_direction' => $this->input('cashflow_direction', CategoryCashflowDirection::Hidden->value),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'name')
                    ->where('user_id', auth()->id()),
            ],
            'icon' => ['required', 'string'],
            'color' => [
                'required',
                'string',
                Rule::enum(CategoryColor::class),
            ],
            'type' => [
                'required',
                'string',
                Rule::in($this->allowedCategoryTypes()),
            ],
            'cashflow_direction' => [
                'required',
                'string',
                Rule::enum(CategoryCashflowDirection::class),
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function allowedCategoryTypes(): array
    {
        $types = [
            CategoryType::Income->value,
            CategoryType::Expense->value,
            CategoryType::Transfer->value,
        ];

        if (Feature::for($this->user())->active(CashflowSavingsInvestmentsAndPeriods::class)) {
            $types[] = CategoryType::Savings->value;
            $types[] = CategoryType::Investment->value;
        }

        return $types;
    }
}
