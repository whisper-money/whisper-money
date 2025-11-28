<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['nullable', 'exists:categories,id'],
            'description' => ['sometimes', 'string'],
            'description_iv' => ['sometimes', 'string', 'size:16'],
            'notes' => ['nullable', 'string'],
            'notes_iv' => ['nullable', 'string', 'size:16'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.exists' => 'The selected category does not exist.',
            'description_iv.size' => 'The description IV must be exactly 16 characters.',
            'notes_iv.size' => 'The notes IV must be exactly 16 characters.',
        ];
    }
}
