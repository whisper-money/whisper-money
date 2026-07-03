<?php

namespace App\Services;

use App\Enums\CategorySource;
use App\Models\AutomationRule;
use App\Models\LabelTransaction;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use JWadhams\JsonLogic;

class AutomationRuleService
{
    /**
     * Per-user rule cache, memoized for the lifetime of this service instance.
     *
     * Bulk re-evaluation calls applyRules() once per transaction; without this
     * every transaction re-queried the user's full rule set (an N+1). A service
     * instance is short-lived (resolved fresh per job/request), so rules created
     * after it is built are never seen mid-run — which is the intended behaviour.
     *
     * @var array<string, EloquentCollection<int, AutomationRule>>
     */
    private array $rulesByUser = [];

    public function applyRules(Transaction $transaction): void
    {
        if ($transaction->description_iv !== null) {
            return;
        }

        $rules = $this->rulesForUser($transaction->user_id);

        if ($rules->isEmpty()) {
            return;
        }

        $transactionData = $this->prepareTransactionData($transaction);
        $matchedRule = $this->evaluateRules($rules, $transactionData);

        if ($matchedRule) {
            $this->applyActions($transaction, $matchedRule);
        }
    }

    /**
     * Determine whether a single rule's conditions match the transaction.
     *
     * Encrypted transactions are skipped because rule evaluation reads the
     * plaintext description and notes which the server cannot access.
     */
    public function ruleMatches(AutomationRule $rule, Transaction $transaction): bool
    {
        if ($transaction->description_iv !== null) {
            return false;
        }

        $transactionData = $this->prepareTransactionData($transaction, $rule);

        try {
            $normalizedRulesJson = $this->normalizeRuleJson($rule->rules_json);

            return JsonLogic::apply($normalizedRulesJson, $transactionData) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Apply a rule's actions to a set of transactions.
     *
     * When $onlyUncategorized is true the set is re-filtered here, at apply
     * time, rather than trusting the caller's (possibly stale) match snapshot.
     * A transaction that was categorized after the snapshot was taken — by the
     * user, another rule, or the AI backfill — is skipped so the rule never
     * silently overwrites a category the user did not ask it to touch.
     *
     * @param  EloquentCollection<int, Transaction>  $transactions
     */
    public function applyRuleActionsToTransactions(EloquentCollection $transactions, AutomationRule $rule, bool $onlyUncategorized = false): int
    {
        if ($transactions->isEmpty()) {
            return 0;
        }

        $rule->loadMissing('labels');
        $transactions->loadMissing('labels');

        if ($onlyUncategorized) {
            $transactions = $transactions->reject(
                fn (Transaction $transaction): bool => $this->shouldSkipForOnlyUncategorized($rule, $transaction),
            );

            if ($transactions->isEmpty()) {
                return 0;
            }
        }

        $changedTransactionIds = [];

        if ($rule->action_category_id !== null) {
            $categoryTransactionIds = $transactions
                ->filter(fn (Transaction $transaction): bool => $transaction->category_id !== $rule->action_category_id)
                ->pluck('id')
                ->all();

            if ($categoryTransactionIds !== []) {
                Transaction::query()
                    ->whereIn('id', $categoryTransactionIds)
                    ->update([
                        'category_id' => $rule->action_category_id,
                        'category_source' => CategorySource::Rule->value,
                        'categorized_by_rule_id' => $rule->id,
                        'updated_at' => now(),
                    ]);

                foreach ($categoryTransactionIds as $transactionId) {
                    $changedTransactionIds[$transactionId] = true;
                }
            }
        }

        if ($rule->action_note && $rule->action_note_iv === null) {
            foreach ($transactions as $transaction) {
                $existingNotes = $transaction->notes ?? '';

                if ($this->noteAlreadyPresent($existingNotes, $rule->action_note)) {
                    continue;
                }

                $transaction->notes = $existingNotes
                    ? $existingNotes."\n".$rule->action_note
                    : $rule->action_note;
                $transaction->saveQuietly();
                $changedTransactionIds[$transaction->id] = true;
            }
        }

        $labelIds = $rule->labels->pluck('id')->all();
        if ($labelIds !== []) {
            $now = now();
            $labelTransactionRows = [];

            foreach ($transactions as $transaction) {
                $transactionLabelIds = $transaction->labels->pluck('id')->all();
                $missingLabelIds = array_diff($labelIds, $transactionLabelIds);

                foreach ($missingLabelIds as $labelId) {
                    $labelTransactionRows[] = [
                        'id' => (string) Str::uuid(),
                        'label_id' => $labelId,
                        'transaction_id' => $transaction->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $changedTransactionIds[$transaction->id] = true;
                }
            }

            if ($labelTransactionRows !== []) {
                LabelTransaction::query()->insertOrIgnore($labelTransactionRows);
            }
        }

        return count($changedTransactionIds);
    }

    /**
     * Whether a transaction should be skipped when "only uncategorized" is on.
     *
     * For category-setting rules: skip if the transaction already has a category.
     * For label-only rules: skip if the transaction already has every label
     * the rule would add (otherwise a label-only rule could never apply).
     */
    public function shouldSkipForOnlyUncategorized(AutomationRule $rule, Transaction $transaction): bool
    {
        if ($rule->action_category_id !== null) {
            return $transaction->category_id !== null;
        }

        $ruleLabelIds = $rule->labels->pluck('id')->all();

        if (empty($ruleLabelIds)) {
            return false;
        }

        $transactionLabelIds = $transaction->labels->pluck('id')->all();

        return empty(array_diff($ruleLabelIds, $transactionLabelIds));
    }

    /**
     * @return array<int, string>
     */
    public function eagerLoadsForRuleEvaluation(AutomationRule $rule): array
    {
        $variables = $this->ruleVariables($rule);
        $eagerLoads = [];

        if (in_array('bank_name', $variables, true)) {
            $eagerLoads[] = 'account.bank';
        } elseif (in_array('account_name', $variables, true)) {
            $eagerLoads[] = 'account';
        }

        if (in_array('category', $variables, true)) {
            $eagerLoads[] = 'category';
        }

        return $eagerLoads;
    }

    /**
     * @return array{description: string, amount: float, transaction_date: string, bank_name: string, account_name: string, category: string|null, notes: string|null, creditor_name: string|null, debtor_name: string|null}
     */
    private function prepareTransactionData(Transaction $transaction, ?AutomationRule $rule = null): array
    {
        $transaction->loadMissing($rule ? $this->eagerLoadsForRuleEvaluation($rule) : ['account.bank', 'category']);

        $account = $transaction->relationLoaded('account') ? $transaction->account : null;
        $bank = $account?->relationLoaded('bank') ? $account->bank : null;
        $category = $transaction->relationLoaded('category') ? $transaction->category : null;

        $accountName = '';
        if ($account && ! $account->encrypted) {
            $accountName = trim($account->name);
        }

        return [
            'description' => $this->normalizeWhitespace(mb_strtolower($transaction->description ?? '')),
            'amount' => $transaction->amount / 100,
            'transaction_date' => $transaction->transaction_date->format('Y-m-d'),
            'bank_name' => mb_strtolower($bank->name ?? ''),
            'account_name' => mb_strtolower($accountName),
            'category' => $category?->name,
            'notes' => $transaction->notes
                ? $this->normalizeWhitespace(mb_strtolower($transaction->notes))
                : null,
            'creditor_name' => $transaction->creditor_name
                ? $this->normalizeWhitespace(mb_strtolower($transaction->creditor_name))
                : null,
            'debtor_name' => $transaction->debtor_name
                ? $this->normalizeWhitespace(mb_strtolower($transaction->debtor_name))
                : null,
        ];
    }

    /**
     * @return EloquentCollection<int, AutomationRule>
     */
    private function rulesForUser(string $userId): EloquentCollection
    {
        return $this->rulesByUser[$userId] ??= AutomationRule::query()
            ->where('user_id', $userId)
            ->with('labels')
            ->orderBy('priority')
            ->get();
    }

    /**
     * @param  Collection<int, AutomationRule>  $rules
     * @param  array<string, mixed>  $transactionData
     */
    private function evaluateRules(Collection $rules, array $transactionData): ?AutomationRule
    {
        foreach ($rules as $rule) {
            try {
                $normalizedRulesJson = $this->normalizeRuleJson($rule->rules_json);
                $result = JsonLogic::apply($normalizedRulesJson, $transactionData);

                if ($result === true) {
                    return $rule;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function ruleVariables(AutomationRule $rule): array
    {
        $variables = [];
        $this->collectRuleVariables($this->normalizeRuleJson($rule->rules_json), $variables);

        return array_values(array_unique($variables));
    }

    /**
     * @param  array<int, string>  $variables
     */
    private function collectRuleVariables(mixed $ruleJson, array &$variables): void
    {
        if (! is_array($ruleJson)) {
            return;
        }

        if (isset($ruleJson['var']) && is_string($ruleJson['var'])) {
            $variables[] = $ruleJson['var'];
        }

        foreach ($ruleJson as $value) {
            $this->collectRuleVariables($value, $variables);
        }
    }

    private function applyActions(Transaction $transaction, AutomationRule $rule): bool
    {
        $changed = false;

        if ($rule->action_category_id !== null
            && $transaction->category_id !== $rule->action_category_id) {
            $transaction->category_id = $rule->action_category_id;
            $transaction->category_source = CategorySource::Rule;
            $transaction->categorized_by_rule_id = $rule->id;
            $changed = true;
        }

        // Only apply plain (unencrypted) notes — encrypted notes require the user's key
        if ($rule->action_note && $rule->action_note_iv === null) {
            $existingNotes = $transaction->notes ?? '';
            $ruleNote = $rule->action_note;

            if (! $this->noteAlreadyPresent($existingNotes, $ruleNote)) {
                $transaction->notes = $existingNotes
                    ? $existingNotes."\n".$ruleNote
                    : $ruleNote;
                $changed = true;
            }
        }

        if ($transaction->isDirty()) {
            $transaction->saveQuietly();
        }

        $labelIds = $rule->labels->pluck('id')->all();
        if (! empty($labelIds)) {
            $result = $transaction->labels()->syncWithoutDetaching($labelIds);
            if (! empty($result['attached'])) {
                $changed = true;
            }
        }

        return $changed;
    }

    private function noteAlreadyPresent(string $existingNotes, string $note): bool
    {
        return mb_strpos($existingNotes, $note) !== false;
    }

    private function normalizeRuleJson(mixed $rulesJson): mixed
    {
        if (is_string($rulesJson)) {
            $decoded = json_decode($rulesJson, true);
            if (is_array($decoded)) {
                return $this->normalizeRuleJson($decoded);
            }

            return mb_strtolower($rulesJson);
        }

        if (is_array($rulesJson)) {
            if (array_is_list($rulesJson)) {
                return array_map(function (mixed $item, int $index): mixed {
                    if ($index === 0 && is_string($item)) {
                        return mb_strtolower($item);
                    }

                    if (is_array($item) && isset($item['var']) && in_array($item['var'], ['description', 'notes'])) {
                        return $item;
                    }

                    return $this->normalizeRuleJson($item);
                }, $rulesJson, array_keys($rulesJson));
            }

            $normalized = [];
            foreach ($rulesJson as $key => $value) {
                $normalized[$key] = $this->normalizeRuleJson($value);
            }

            return $normalized;
        }

        return $rulesJson;
    }

    private function normalizeWhitespace(string $str): string
    {
        return trim(preg_replace('/\s+/', ' ', $str));
    }
}
