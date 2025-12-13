<?php

namespace App\Http\Requests\Settings;

use App\Enums\LabelColor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLabelRequest extends FormRequest
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
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('labels', 'name')
                    ->where('user_id', auth()->id())
                    ->whereNull('deleted_at')
                    ->ignore($this->route('label')),
            ],
            'color' => [
                'sometimes',
                'required',
                'string',
                Rule::enum(LabelColor::class),
            ],
        ];
    }
}
