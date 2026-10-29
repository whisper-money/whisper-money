<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\AddsRefusals;
use App\Http\Requests\Api\V1\Concerns\ResolvesRouteAutomationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ApplyAutomationRuleRequest extends FormRequest
{
    use AddsRefusals, ResolvesRouteAutomationRule;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            /**
             * `true` (the default) only previews: it reports how many transactions the rule matches, with a sample of them, and changes nothing. Send `false` to apply the rule's actions to every match.
             *
             * @default true
             *
             * @example true
             */
            'dry_run' => ['sometimes', 'boolean'],
            /**
             * `true` (the default) skips the transactions that already have a category or, for a rule with labels and no category, that already carry all its labels. Send `false` to also take the categorized ones: the rule then overwrites the category the user or another rule gave them. Preview that combination before applying it.
             *
             * @default true
             */
            'only_uncategorized' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Refuse unknown fields.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->addRefusals($validator, $this->unknownFieldRefusals())];
    }

    public function dryRun(): bool
    {
        return $this->boolean('dry_run', true);
    }

    public function onlyUncategorized(): bool
    {
        return $this->boolean('only_uncategorized', true);
    }
}
