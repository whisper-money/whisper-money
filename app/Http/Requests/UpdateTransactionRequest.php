<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => [
                'nullable',
                Rule::exists('categories', 'id')->where(function ($query) {
                    $query->where('user_id', $this->user()->id);
                }),
            ],
            'description' => ['sometimes', 'string'],
            'description_iv' => ['nullable', 'string', 'size:16'],
            'notes' => ['nullable', 'string'],
            'notes_iv' => ['nullable', 'string', 'size:16'],
            'label_ids' => ['nullable', 'array'],
            'label_ids.*' => [
                'required',
                'string',
                'uuid',
                Rule::exists('labels', 'id')->where(function ($query) {
                    $query->where('user_id', $this->user()->id);
                }),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.exists' => 'The selected category does not exist.',
            'description_iv.size' => 'The description IV must be exactly 16 characters.',
            'notes_iv.size' => 'The notes IV must be exactly 16 characters.',
            'label_ids.*.exists' => 'One or more selected labels do not exist or do not belong to you.',
        ];
    }
}
