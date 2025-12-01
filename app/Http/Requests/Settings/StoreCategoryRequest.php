<?php

namespace App\Http\Requests\Settings;

use App\Enums\CategoryType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
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
            'name' => ['required', 'string', 'max:255'],
            'icon' => ['required', 'string'],
            'color' => [
                'required',
                'string',
                Rule::in([
                    'amber',
                    'blue',
                    'cyan',
                    'emerald',
                    'gray',
                    'green',
                    'indigo',
                    'orange',
                    'pink',
                    'purple',
                    'red',
                    'slate',
                    'teal',
                    'yellow',
                ]),
            ],
            'type' => [
                'required',
                'string',
                Rule::enum(CategoryType::class),
            ],
        ];
    }
}
