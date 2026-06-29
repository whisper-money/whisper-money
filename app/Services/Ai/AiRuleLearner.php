<?php

namespace App\Services\Ai;

use App\Enums\RuleOrigin;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\Ai\Contracts\TransactionMatcher;
use Illuminate\Support\Str;

/**
 * Owns the deterministic rules that back AI auto-categorization, in two flavours:
 *
 * - Tier 2 (learn): turn a confident, unambiguous, merchant-keyed categorization
 *   into an ai-owned rule so every future transaction from the same merchant is
 *   categorized for free and consistently — no repeat model call.
 * - Learning from corrections (learnFromCorrection): turn a user's correction of
 *   an AI categorization into a correction-owned rule keyed on the merchant or the
 *   description's distinctive tokens, so the same mistake is never repeated.
 *
 * To avoid rule sprawl, all of a user's rules for one category and origin live in
 * a SINGLE rule whose conditions are OR'd together; a new key is appended rather
 * than spawning another. AI never touches a rule the user created or edited
 * (origin = user). New ai/correction rules sit at the lowest priority (highest
 * number) so a user's own rules always win.
 */
class AiRuleLearner
{
    public function __construct(
        private readonly DescriptionTokenizer $tokenizer,
        private readonly TransactionMatcher $matcher,
    ) {}

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
     * Turn a user's correction into a deterministic, forward-looking rule so the
     * same merchant (or the same distinctive description) is never mis-categorized
     * the same way again — the next matching transaction is categorized by this
     * rule before the model ever runs.
     *
     * The rule is keyed on the merchant when one exists (stable even as the
     * description varies); otherwise on the description's distinctive tokens,
     * guarded so an over-broad token can never silently mis-file en masse. A key
     * lives in exactly one correction rule, so changing your mind moves it.
     * Returns the rule that now carries the correction, or null when nothing safe
     * could be learned (correcting to uncategorized, no usable key, or guarded).
     */
    public function learnFromCorrection(Transaction $transaction, ?string $toCategoryId): ?AutomationRule
    {
        if ($toCategoryId === null) {
            return null;
        }

        $clause = $this->correctionClause($transaction);

        if ($clause === null) {
            return null;
        }

        $this->releaseClauseFromOtherCorrectionRules($transaction->user_id, $toCategoryId, $clause);

        $rule = $this->existingCorrectionRule($transaction->user_id, $toCategoryId)
            ?? $this->createCorrectionRule($transaction->user_id, $toCategoryId);

        $this->appendClause($rule, $clause);

        return $rule;
    }

    /**
     * The JsonLogic clause that recognises future transactions like this one:
     * a merchant equality when a clean merchant key exists, otherwise an AND of
     * "description contains" over the distinctive tokens. Null when neither is
     * usable or the description token set is too broad to be safe.
     *
     * @return array<string, mixed>|null
     */
    private function correctionClause(Transaction $transaction): ?array
    {
        $merchant = $this->merchantKey($transaction);

        if ($merchant !== null) {
            [$field, $token] = $merchant;

            return ['==' => [['var' => $field], $token]];
        }

        return $this->descriptionClause($transaction);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function descriptionClause(Transaction $transaction): ?array
    {
        if ($transaction->description_iv !== null) {
            return null;
        }

        $tokens = $this->distinctiveDescriptionTokens($transaction);

        if ($tokens === []) {
            return null;
        }

        if ($this->isOverbroad($transaction, $tokens)) {
            return null;
        }

        $clauses = array_map(
            fn (string $token): array => ['in' => [$token, ['var' => 'description']]],
            $tokens,
        );

        return count($clauses) === 1 ? $clauses[0] : ['and' => $clauses];
    }

    /**
     * The distinctive description tokens of this transaction relative to the
     * user's own transaction vocabulary (the noise corpus). Corrections are rare
     * and user-driven, so loading the descriptions here is acceptable.
     *
     * @return list<string>
     */
    private function distinctiveDescriptionTokens(Transaction $transaction): array
    {
        $descriptions = Transaction::query()
            ->where('user_id', $transaction->user_id)
            ->whereNull('description_iv')
            ->pluck('description')
            ->all();

        $frequency = $this->tokenizer->documentFrequency($descriptions);
        $threshold = count($descriptions) * (float) config('ai_suggestions.noise_token_fraction');

        return $this->tokenizer->distinctiveTokens((string) $transaction->description, $frequency, $threshold);
    }

    /**
     * Whether a description rule over these tokens would match so many of the
     * user's uncategorized transactions that it risks mis-filing en masse.
     *
     * @param  list<string>  $tokens
     */
    private function isOverbroad(Transaction $transaction, array $tokens): bool
    {
        $total = $this->matcher->total($transaction->user);

        if ($total === 0) {
            return false;
        }

        $conditions = array_map(
            fn (string $token): array => ['field' => 'description', 'operator' => 'contains', 'token' => $token],
            $tokens,
        );

        $fraction = $this->matcher->countMatchingAll($transaction->user, $conditions) / $total;

        return $fraction > (float) config('ai_suggestions.overbroad_fraction');
    }

    private function existingCorrectionRule(string $userId, string $categoryId): ?AutomationRule
    {
        return AutomationRule::query()
            ->where('user_id', $userId)
            ->where('action_category_id', $categoryId)
            ->origin(RuleOrigin::Correction)
            ->first();
    }

    private function createCorrectionRule(string $userId, string $categoryId): AutomationRule
    {
        // Appended at the bottom, like ai rules. A correction and an ai rule never
        // compete on the same key — forgetFromAiRules() strips the merchant from
        // every ai rule the moment the correction is made. ponytail: cross-key
        // precedence is creation-order; band correction above ai only if it bites.
        $priority = (int) AutomationRule::query()->where('user_id', $userId)->max('priority');

        return AutomationRule::create([
            'user_id' => $userId,
            'title' => $this->title($categoryId, []),
            'priority' => $priority + 1,
            'origin' => RuleOrigin::Correction,
            'rules_json' => [],
            'action_category_id' => $categoryId,
        ]);
    }

    /**
     * Keep a key in exactly one correction rule: if the user re-corrects the same
     * merchant/description to a different category, drop the identical clause from
     * any other correction rule (deleting it when it becomes empty).
     *
     * @param  array<string, mixed>  $clause
     */
    private function releaseClauseFromOtherCorrectionRules(string $userId, string $keepCategoryId, array $clause): void
    {
        $rules = AutomationRule::query()
            ->where('user_id', $userId)
            ->where('action_category_id', '!=', $keepCategoryId)
            ->origin(RuleOrigin::Correction)
            ->get();

        foreach ($rules as $rule) {
            $clauses = $this->clauses($rule->rules_json);
            $remaining = array_values(array_filter($clauses, fn (array $existing): bool => $existing != $clause));

            if (count($remaining) === count($clauses)) {
                continue;
            }

            if ($remaining === []) {
                $rule->delete();

                continue;
            }

            $rule->rules_json = count($remaining) === 1 ? $remaining[0] : ['or' => $remaining];
            $rule->title = $this->title((string) $rule->action_category_id, $this->tokens($remaining));
            $rule->save();
        }
    }

    /**
     * Append a clause to a rule's OR set, skipping an identical existing one.
     *
     * @param  array<string, mixed>  $clause
     */
    private function appendClause(AutomationRule $rule, array $clause): void
    {
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
     * Self-heal every one of the user's ai rules that carries this transaction's
     * merchant — not just the rule that happened to label this transaction. A
     * merchant can be forced by an ai rule even when the corrected transaction was
     * a direct model label (no rule id) or labeled by a different rule; unless all
     * of them release the merchant, an ai rule could out-rank the correction and
     * re-apply the wrong category to the next transaction from that merchant.
     */
    public function forgetFromAiRules(Transaction $transaction): void
    {
        $rules = AutomationRule::query()
            ->where('user_id', $transaction->user_id)
            ->origin(RuleOrigin::Ai)
            ->get();

        foreach ($rules as $rule) {
            $this->forget($rule, $transaction);
        }
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
        $this->appendClause($rule, ['==' => [['var' => $field], $token]]);
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
     * Human-readable token per clause, for the rule title: the value of a
     * merchant equality, the needle of a "contains", or the joined needles of an
     * AND-of-contains.
     *
     * @param  list<array<string, mixed>>  $clauses
     * @return list<string>
     */
    private function tokens(array $clauses): array
    {
        $tokens = [];

        foreach ($clauses as $clause) {
            $token = $this->clauseLabel($clause);

            if ($token !== null) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * @param  array<string, mixed>  $clause
     */
    private function clauseLabel(array $clause): ?string
    {
        if (isset($clause['=='])) {
            $token = $clause['=='][1] ?? null;

            return is_string($token) ? $token : null;
        }

        if (isset($clause['in'])) {
            $token = $clause['in'][0] ?? null;

            return is_string($token) ? $token : null;
        }

        if (isset($clause['and']) && is_array($clause['and'])) {
            $parts = [];

            foreach ($clause['and'] as $sub) {
                $needle = $sub['in'][0] ?? null;

                if (is_string($needle)) {
                    $parts[] = $needle;
                }
            }

            return $parts === [] ? null : implode(' + ', $parts);
        }

        return null;
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
