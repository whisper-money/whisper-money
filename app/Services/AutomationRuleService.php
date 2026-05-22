<?php

namespace App\Services;

use App\Models\AutomationRule;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use JWadhams\JsonLogic;

class AutomationRuleService
{
    public function applyRules(Transaction $transaction): void
    {
        if ($transaction->description_iv !== null) {
            return;
        }

        $rules = AutomationRule::query()
            ->where('user_id', $transaction->user_id)
            ->with('labels')
            ->orderBy('priority')
            ->get();

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

        $transactionData = $this->prepareTransactionData($transaction);

        try {
            $normalizedRulesJson = $this->normalizeRuleJson($rule->rules_json);

            return JsonLogic::apply($normalizedRulesJson, $transactionData) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Apply a single rule's actions to the transaction, ignoring rule
     * priority and other rules. The transaction must already match the rule.
     *
     * Returns true if any attribute of the transaction was changed.
     */
    public function applyRuleActions(Transaction $transaction, AutomationRule $rule): bool
    {
        return $this->applyActions($transaction, $rule);
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
     * @return array{description: string, amount: float, transaction_date: string, bank_name: string, account_name: string, category: string|null, notes: string|null}
     */
    private function prepareTransactionData(Transaction $transaction): array
    {
        $transaction->loadMissing(['account.bank', 'category']);

        $account = $transaction->account;
        $bank = $account?->bank;

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
            'category' => $transaction->category?->name,
            'notes' => $transaction->notes
                ? $this->normalizeWhitespace(mb_strtolower($transaction->notes))
                : null,
        ];
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

    private function applyActions(Transaction $transaction, AutomationRule $rule): bool
    {
        $changed = false;

        if ($rule->action_category_id !== null
            && $transaction->category_id !== $rule->action_category_id) {
            $transaction->category_id = $rule->action_category_id;
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
