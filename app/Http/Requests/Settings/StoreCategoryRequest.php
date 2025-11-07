<?php

namespace App\Http\Requests\Settings;

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
                    'red',
                    'orange',
                    'amber',
                    'yellow',
                    'lime',
                    'green',
                    'emerald',
                    'teal',
                    'cyan',
                    'sky',
                    'blue',
                    'indigo',
                    'violet',
                    'purple',
                    'fuchsia',
                    'pink',
                    'rose',
                ]),
            ],
        ];
    }
}
