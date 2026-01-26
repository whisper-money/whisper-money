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
                'string',
                Rule::exists('categories', 'id')->where(function ($query) {
                    $query->where('user_id', auth()->id());
                }),
            ],
            'action_note' => ['nullable', 'string'],
            'action_note_iv' => ['nullable', 'string', 'required_with:action_note'],
            'action_label_ids' => ['nullable', 'array'],
            'action_label_ids.*' => [
                'required',
                'string',
                'uuid',
                Rule::exists('labels', 'id')->where(function ($query) {
                    $query->where('user_id', auth()->id());
                }),
            ],
        ];
    }

    /**
     * Decode the rules_json string into an array so the model's array cast doesn't double-encode it.
     */
    protected function passedValidation(): void
    {
        $this->merge([
            'rules_json' => json_decode($this->rules_json, true),
        ]);
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $hasLabels = ! empty($this->action_label_ids);
            if (! $this->action_category_id && ! $hasLabels) {
                $validator->errors()->add('action_category_id', 'At least one action (category or labels) must be provided.');
            }
        });
    }
}
