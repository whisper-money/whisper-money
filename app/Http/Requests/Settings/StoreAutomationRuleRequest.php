<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAutomationRuleRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'priority' => ['required', 'integer', 'min:0'],
            'rules_json' => ['required', 'json', function ($attribute, $value, $fail) {
                $decoded = json_decode($value, true);
                if (! is_array($decoded) || empty($decoded)) {
                    $fail('The rules JSON must be a valid JsonLogic object.');
                }
            }],
            'action_category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where(function ($query) {
                    $query->where('user_id', auth()->id());
                }),
            ],
            'action_note' => ['nullable', 'string'],
            'action_note_iv' => ['nullable', 'string', 'required_with:action_note'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->action_category_id && ! $this->action_note) {
                $validator->errors()->add('action_category_id', 'At least one action (category or note) must be provided.');
            }
        });
    }
}
