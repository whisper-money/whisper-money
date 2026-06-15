<?php

namespace App\Services\Ai;

use App\Enums\RuleOrigin;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Str;

/**
 * Tier 2 of AI auto-categorization: turn a confident, unambiguous, merchant-keyed
 * categorization into a deterministic rule so every future transaction from the
 * same merchant is categorized for free and consistently — no repeat model call.
 *
 * To avoid rule sprawl, all of a user's AI-categorizations for one category live
 * in a SINGLE ai-owned rule whose conditions are OR'd together; a new merchant is
 * appended to that rule rather than spawning another. AI never touches a rule the
 * user created or edited (origin = user). New ai rules sit at the lowest priority
 * (highest number) so a user's own rules always win.
 */
class AiRuleLearner
{
    public function learn(CategorizationOutcome $outcome): ?AutomationRule
    {
        if (! $outcome->merchantUnambiguous) {
            return null;
        }

        if ($outcome->confidence < (float) config('ai_categorization.rule_confidence')) {
            return null;
        }

        $key = $this->merchantKey($outcome->transaction);

        if ($key === null) {
            return null;
        }

        [$field, $token] = $key;

        $rule = $this->existingAiRule($outcome->transaction->user_id, $outcome->categoryId)
            ?? $this->createAiRule($outcome->transaction->user_id, $outcome->categoryId);

        $this->appendCondition($rule, $field, $token);

        $outcome->transaction->categorized_by_rule_id = $rule->id;
        $outcome->transaction->saveQuietly();

        return $rule;
    }

    /**
     * @return array{0: string, 1: string}|null [field, token]
     */
    private function merchantKey(Transaction $transaction): ?array
    {
        foreach (['creditor_name', 'debtor_name'] as $field) {
            $value = $this->normalize((string) ($transaction->{$field} ?? ''));

            if ($value !== '') {
                return [$field, $value];
            }
        }

        return null;
    }

    private function existingAiRule(string $userId, string $categoryId): ?AutomationRule
    {
        return AutomationRule::query()
            ->where('user_id', $userId)
            ->where('action_category_id', $categoryId)
            ->origin(RuleOrigin::Ai)
            ->first();
    }

    private function createAiRule(string $userId, string $categoryId): AutomationRule
    {
        $priority = (int) AutomationRule::query()->where('user_id', $userId)->max('priority');

        return AutomationRule::create([
            'user_id' => $userId,
            'title' => $this->title($categoryId, []),
            'priority' => $priority + 1,
            'origin' => RuleOrigin::Ai,
            'rules_json' => [],
            'action_category_id' => $categoryId,
        ]);
    }

    /**
     * Self-heal after a user corrects a transaction this ai-owned rule labeled:
     * drop the merchant condition(s) matching the transaction so the rule stops
     * forcing the wrong category on future transactions from that merchant. The
     * rule is deleted when no condition remains.
     */
    public function forget(AutomationRule $rule, Transaction $transaction): void
    {
        $tokens = [];

        foreach (['creditor_name', 'debtor_name'] as $field) {
            $value = $this->normalize((string) ($transaction->{$field} ?? ''));

            if ($value !== '') {
                $tokens[$value] = true;
            }
        }

        if ($tokens === []) {
            return;
        }

        $clauses = $this->clauses($rule->rules_json);
        $remaining = array_values(array_filter($clauses, function (array $clause) use ($tokens): bool {
            $token = $clause['=='][1] ?? null;

            return ! (is_string($token) && isset($tokens[$token]));
        }));

        if (count($remaining) === count($clauses)) {
            return;
        }

        if ($remaining === []) {
            $rule->delete();

            return;
        }

        $rule->rules_json = count($remaining) === 1 ? $remaining[0] : ['or' => $remaining];
        $rule->title = $this->title((string) $rule->action_category_id, $this->tokens($remaining));
        $rule->save();
    }

    private function appendCondition(AutomationRule $rule, string $field, string $token): void
    {
        $clause = ['==' => [['var' => $field], $token]];
        $clauses = $this->clauses($rule->rules_json);

        foreach ($clauses as $existing) {
            if ($existing == $clause) {
                return;
            }
        }

        $clauses[] = $clause;

        $rule->rules_json = count($clauses) === 1 ? $clauses[0] : ['or' => $clauses];
        $rule->title = $this->title((string) $rule->action_category_id, $this->tokens($clauses));
        $rule->save();
    }

    /**
     * The individual condition clauses of a rule, normalised to a flat list
     * regardless of whether it is a single clause or an OR of several.
     *
     * @param  mixed  $rulesJson
     * @return list<array<string, mixed>>
     */
    private function clauses($rulesJson): array
    {
        if (! is_array($rulesJson) || $rulesJson === []) {
            return [];
        }

        if (isset($rulesJson['or']) && is_array($rulesJson['or'])) {
            return array_values($rulesJson['or']);
        }

        return [$rulesJson];
    }

    /**
     * @param  list<array<string, mixed>>  $clauses
     * @return list<string>
     */
    private function tokens(array $clauses): array
    {
        $tokens = [];

        foreach ($clauses as $clause) {
            $token = $clause['=='][1] ?? null;

            if (is_string($token)) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * @param  list<string>  $tokens
     */
    private function title(string $categoryId, array $tokens): string
    {
        $categoryName = Category::query()->whereKey($categoryId)->value('name') ?? '';

        if ($tokens === []) {
            return trim($categoryName.' (AI)');
        }

        $label = implode(', ', array_map(fn (string $token): string => Str::title($token), array_slice($tokens, 0, 3)));

        if (count($tokens) > 3) {
            $label .= '…';
        }

        return trim($label.' → '.$categoryName);
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower($value)) ?? '');
    }
}
