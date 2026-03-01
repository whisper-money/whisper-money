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

    private function applyActions(Transaction $transaction, AutomationRule $rule): void
    {
        $dirty = false;

        if ($rule->action_category_id) {
            $transaction->category_id = $rule->action_category_id;
            $dirty = true;
        }

        // Only apply plain (unencrypted) notes — encrypted notes require the user's key
        if ($rule->action_note && $rule->action_note_iv === null) {
            $existingNotes = $transaction->notes ?? '';
            $ruleNote = $rule->action_note;

            if (! $this->noteAlreadyPresent($existingNotes, $ruleNote)) {
                $transaction->notes = $existingNotes
                    ? $existingNotes."\n".$ruleNote
                    : $ruleNote;
                $dirty = true;
            }
        }

        if ($dirty) {
            $transaction->saveQuietly();
        }

        $labelIds = $rule->labels->pluck('id')->all();
        if (! empty($labelIds)) {
            $transaction->labels()->syncWithoutDetaching($labelIds);
        }
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
