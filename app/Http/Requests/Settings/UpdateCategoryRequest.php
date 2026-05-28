<?php

namespace App\Http\Requests\Settings;

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryColor;
use App\Enums\CategoryType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
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
        $type = CategoryType::tryFrom((string) $this->input('type'));

        if (in_array($type, [CategoryType::Savings, CategoryType::Investment], true)) {
            $this->merge([
                'cashflow_direction' => CategoryCashflowDirection::Outflow->value,
            ]);

            return;
        }

        if ($type !== CategoryType::Transfer) {
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
                    ->where('user_id', auth()->id())
                    ->withoutTrashed()
                    ->ignore($this->route('category')),
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
                Rule::enum(CategoryType::class),
            ],
            'cashflow_direction' => [
                'required',
                'string',
                Rule::enum(CategoryCashflowDirection::class),
            ],
        ];
    }
}
