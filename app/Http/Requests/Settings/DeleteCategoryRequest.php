<?php

namespace App\Http\Requests\Settings;

use App\Enums\CategoryDeletionStrategy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeleteCategoryRequest extends FormRequest
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
            'strategy' => ['nullable', 'string', Rule::enum(CategoryDeletionStrategy::class)],
        ];
    }

    /**
     * The chosen strategy for handling children, defaulting to lifting them up
     * to the deleted category's own parent.
     */
    public function strategy(): CategoryDeletionStrategy
    {
        return CategoryDeletionStrategy::tryFrom((string) $this->input('strategy'))
            ?? CategoryDeletionStrategy::Reparent;
    }
}
