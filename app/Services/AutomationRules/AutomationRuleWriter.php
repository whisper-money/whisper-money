<?php

namespace App\Services\AutomationRules;

use App\Enums\RuleOrigin;
use App\Models\AutomationRule;
use App\Models\Space;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Creating and editing an automation rule, shared by the rule MCP tools and
 * `/api/v1/automation-rules`, with the rules both validate the plain fields
 * with. The conditions arrive checked against RuleConditions and the category
 * and labels already found in the space, each caller naming the valid ids its
 * own way. A rule always keeps at least one action, a category or labels;
 * leaving it without one is a ValidationException under `action_category_id`.
 *
 * Every write touches the rule: its `updated_at` keys the match cache of
 * AutomationRuleApplier, so a preview never outlives the rule it was made for.
 */
class AutomationRuleWriter
{
    /**
     * @return array<string, array<mixed>>
     */
    public static function createRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.AutomationRule::MAX_TITLE_LENGTH],
            'priority' => ['required', 'integer', 'min:0'],
            'action_note' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * Every field is optional: only what is sent changes.
     *
     * @return array<string, array<mixed>>
     */
    public static function updateRules(): array
    {
        return array_map(
            fn (array $rules): array => ['sometimes', ...array_filter($rules, fn (mixed $rule): bool => ! in_array($rule, ['required', 'sometimes'], true))],
            self::createRules(),
        );
    }

    /**
     * @param  array{title: string, priority: int, rules_json: array<string, mixed>, action_category_id: ?string, action_note: ?string}  $attributes
     * @param  list<string>  $labelIds
     */
    public function create(User $user, Space $space, array $attributes, array $labelIds): AutomationRule
    {
        if ($attributes['action_category_id'] === null && $labelIds === []) {
            throw ValidationException::withMessages([
                'action_category_id' => 'At least one action is required: pass action_category_id and/or action_label_ids.',
            ]);
        }

        $rule = new AutomationRule([
            ...$attributes,
            'user_id' => $user->id,
            'space_id' => $space->id,
            'origin' => RuleOrigin::User->value,
        ]);
        $rule->save();

        if ($labelIds !== []) {
            $rule->labels()->sync($labelIds);
            $rule->touch();
        }

        return $rule;
    }

    /**
     * Change only the fields in $changes.
     *
     * @param  array<string, mixed>  $changes  any of `title`, `priority`, `rules_json`, `action_category_id` and `action_note`
     * @param  list<string>|null  $labelIds  the replacement labels, or null to keep the current ones
     */
    public function update(AutomationRule $rule, array $changes, ?array $labelIds): AutomationRule
    {
        $rule->fill($changes);

        // The rule must keep at least one action. Compare against the labels it
        // would have after this edit (the new set if provided, else current).
        $labelCount = $labelIds !== null ? count($labelIds) : $rule->labels()->count();

        if ($rule->action_category_id === null && $labelCount === 0) {
            throw ValidationException::withMessages([
                'action_category_id' => 'An automation rule must keep at least one action: a category or labels.',
            ]);
        }

        $rule->save();

        if ($labelIds !== null) {
            $rule->labels()->sync($labelIds);
        }

        $rule->touch();

        return $rule->refresh();
    }
}
