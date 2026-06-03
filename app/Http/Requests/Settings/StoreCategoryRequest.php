<?php

namespace App\Http\Requests\Settings;

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryColor;
use App\Enums\CategoryType;
use App\Http\Requests\Settings\Concerns\PreparesCategoryAttributes;
use App\Rules\ValidCategoryParent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    use PreparesCategoryAttributes;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
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
                    ->where(fn ($query) => $this->scopeUniqueToSiblings($query))
                    ->withoutTrashed(),
            ],
            'parent_id' => [
                'nullable',
                'string',
                'uuid',
                new ValidCategoryParent(null),
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
