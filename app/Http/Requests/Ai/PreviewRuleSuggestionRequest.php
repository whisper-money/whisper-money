<?php

namespace App\Http\Requests\Ai;

use App\Services\Ai\UncategorizedTransactionMatcher;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewRuleSuggestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'match_field' => ['required', 'string', Rule::in(UncategorizedTransactionMatcher::ALLOWED_FIELDS)],
            'match_operator' => ['required', 'string', Rule::in(['contains', 'equals'])],
            'match_token' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }
}
