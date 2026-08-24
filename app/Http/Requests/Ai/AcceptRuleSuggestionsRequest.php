<?php

namespace App\Http\Requests\Ai;

use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use App\Services\Ai\UncategorizedTransactionMatcher;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AcceptRuleSuggestionsRequest extends FormRequest
{
    use ValidatesUserOwnedResources;

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
            'suggestions' => ['required', 'array', 'min:1'],
            'suggestions.*.ids' => ['required', 'array', 'min:1'],
            'suggestions.*.ids.*' => ['uuid'],
            'suggestions.*.values' => ['required', 'array', 'min:1'],
            'suggestions.*.values.*.match_field' => ['required', 'string', Rule::in(UncategorizedTransactionMatcher::ALLOWED_FIELDS)],
            'suggestions.*.values.*.match_operator' => ['required', 'string', Rule::in(['contains', 'equals'])],
            'suggestions.*.values.*.match_token' => ['required', 'string', 'min:1', 'max:255'],
            'suggestions.*.proposed_category_id' => ['nullable', 'string', $this->userOwned('categories')],
            'suggestions.*.new_category_name' => ['nullable', 'string', 'max:255'],
            'suggestions.*.new_category_direction' => ['nullable', 'string', Rule::in(['inflow', 'outflow'])],
        ];
    }

    /**
     * Each accepted group must resolve to a category target.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            foreach ((array) $this->input('suggestions', []) as $index => $group) {
                $hasExisting = filled($group['proposed_category_id'] ?? null);
                $hasNew = filled($group['new_category_name'] ?? null);

                if (! $hasExisting && ! $hasNew) {
                    $validator->errors()->add(
                        "suggestions.{$index}.proposed_category_id",
                        'Each suggestion needs an existing category or a new category name.',
                    );
                }
            }
        });
    }
}
