<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Category;
use App\Models\Label;
use App\Services\AutomationRules\RuleConditions;
use Closure;
use Illuminate\Contracts\Validation\Validator;

/**
 * What the automation rule create and update requests share: the
 * AutomationRuleWriter rules and the RuleConditions checks the MCP tools
 * validate with too, and the category and labels the rule applies, checked to
 * be in the request's space. The conditions come as a JSON object, never as
 * the JSON-encoded string the MCP also accepts.
 */
trait ValidatesAutomationRuleWrites
{
    use AddsRefusals, ChecksIdsInSpace;

    /**
     * @param  array<string, array<mixed>>  $rules  the AutomationRuleWriter rules for the write
     * @param  string  $presence  `present` on create, `sometimes` on update: an empty object is RuleConditions' to refuse
     * @return array<string, array<mixed>>
     */
    protected function automationRuleFields(array $rules, string $presence): array
    {
        return [
            /**
             * The rule's name.
             *
             * @example Groceries
             */
            'title' => $rules['title'],
            /**
             * The order the rule is evaluated in, lowest first. A new transaction gets the actions of the first rule it matches, and no other.
             *
             * @example 10
             */
            'priority' => $rules['priority'],
            /**
             * The conditions a transaction must meet, as one JsonLogic object. Amounts in here are in MAJOR units (`12.50`), unlike every other amount in the API, and signed: an expense is negative. Text is compared lowercased, so write values in lowercase. Only these operators are accepted: `and`, `or`, `!`, `==`, `!=`, `>`, `>=`, `<`, `<=`, `in` and `var`; and only these variables: `description`, `notes`, `creditor_name`, `debtor_name`, `account_name`, `bank_name`, `category`, `transaction_date` (`YYYY-MM-DD`) and `amount`. "Contains" is `{"in": ["netflix", {"var": "description"}]}`, a negation wraps a condition in `!`, and `and` / `or` take a list and nest freely. "Is not" and "does not contain" also match a transaction whose field is empty.
             *
             * @var array<string, mixed>
             *
             * @example {"and": [{"in": ["mercadona", {"var": "description"}]}, {"<": [{"var": "amount"}, 0]}]}
             */
            'conditions' => ['bail', $presence, $this->validConditions(...)],
            /**
             * The id of the category to give the matching transactions, or `null` for none. A rule needs a category, labels or both.
             *
             * @format uuid
             */
            'action_category_id' => ['sometimes', 'nullable', 'string', $this->inSpace(Category::class, 'a category')],
            /**
             * The ids of the labels to add to the matching transactions, each a label of the space. On update the list replaces the rule's labels; an empty one removes them all.
             *
             * @example ["0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23"]
             */
            'action_label_ids' => ['sometimes', 'array'],
            /**
             * @format uuid
             */
            'action_label_ids.*' => ['string', 'distinct:ignore_case', $this->inSpace(Label::class, 'a label')],
            /**
             * A note to append to the matching transactions' notes, or `null` for none.
             *
             * @example Weekly shop
             */
            'action_note' => $rules['action_note'],
        ];
    }

    /**
     * The validated fields as AutomationRuleWriter takes them: only the ones
     * sent, the conditions under `rules_json`, and without the labels.
     *
     * @return array<string, mixed>
     */
    public function ruleAttributes(): array
    {
        $validated = $this->safe()->except(['conditions', 'action_label_ids']);

        if ($this->has('conditions')) {
            $validated['rules_json'] = $this->validated('conditions');
        }

        return $validated;
    }

    /**
     * The labels sent, or null when the request leaves them alone.
     *
     * @return list<string>|null
     */
    public function labelIds(): ?array
    {
        return $this->has('action_label_ids') ? array_values($this->validated('action_label_ids')) : null;
    }

    /**
     * Read the conditions as sent. The global TrimStrings and
     * ConvertEmptyStringsToNull middleware would otherwise rewrite the values
     * they compare against (`" netflix "`, `""`), so a rule would match
     * something else than the same conditions sent through the MCP.
     */
    protected function prepareForValidation(): void
    {
        $sent = json_decode($this->getContent(), true);

        if (is_array($sent) && array_key_exists('conditions', $sent)) {
            $this->merge(['conditions' => $sent['conditions']]);
        }
    }

    private function validConditions(string $attribute, mixed $value, Closure $fail): void
    {
        $error = RuleConditions::error($value, $attribute);

        if ($error !== null) {
            $fail($error);
        }
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->addRefusals($validator, $this->unknownFieldRefusals())];
    }
}
