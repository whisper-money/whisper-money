<?php

namespace App\Http\Requests\Settings;

use App\Models\Label;
use App\Services\Labels\LabelWriter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ArchiveLabelRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $label = $this->route('label');

        return $label instanceof Label && $this->user()?->can('update', $label) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'archived' => ['required', 'boolean'],
        ];
    }

    /**
     * The label of a savings goal is managed through the goal.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (LabelWriter::savingsGoalLabelErrors($this->route('label'), ['archived'], deleting: false) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        }];
    }
}
