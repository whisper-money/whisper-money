<?php

namespace App\Services\Ai;

use App\Enums\CategorySource;
use App\Enums\RuleOrigin;
use App\Models\AutomationRule;
use App\Models\CategoryCorrection;
use App\Models\Transaction;

/**
 * Runs when a user overrides a transaction's category. If the category being
 * replaced was assigned by the system — by AI directly, by an ai-owned rule, or
 * by a rule learned from an earlier correction — it learns the user's choice as
 * a deterministic, forward-looking rule so the same mistake is never repeated.
 *
 * For AI-driven corrections it also records the calibration signal and self-heals
 * the ai rule that mislabeled the merchant. User-owned rules, bank categories and
 * one-off manual categorizations are never learned from or touched.
 *
 * Must be called BEFORE the new category is written, while the transaction still
 * holds its previous categorization. Returns the rule that now carries the
 * correction (for an "undo" affordance), or null when nothing was learned.
 */
class CategoryOverrideHandler
{
    public function __construct(private readonly AiRuleLearner $learner) {}

    public function record(Transaction $transaction, ?string $newCategoryId): ?AutomationRule
    {
        if ($newCategoryId === $transaction->category_id) {
            return null;
        }

        $rule = $transaction->categorized_by_rule_id !== null
            ? AutomationRule::query()->find($transaction->categorized_by_rule_id)
            : null;

        $ruleOrigin = $rule?->origin;
        $aiDriven = $transaction->category_source === CategorySource::Ai || $ruleOrigin === RuleOrigin::Ai;
        $learnable = $aiDriven || $ruleOrigin === RuleOrigin::Correction;

        if (! $learnable) {
            return null;
        }

        // The correction signal calibrates AI accuracy, so only AI assignments
        // are logged — a correction rule overruling itself is not an AI miss.
        if ($aiDriven) {
            CategoryCorrection::create([
                'user_id' => $transaction->user_id,
                'transaction_id' => $transaction->id,
                'from_category_id' => $transaction->category_id,
                'to_category_id' => $newCategoryId,
                'source' => $transaction->category_source ?? CategorySource::Rule,
                'confidence' => $transaction->ai_confidence,
            ]);
        }

        // Stop every ai rule from forcing the wrong category on this merchant
        // again — including ai rules that did not label this transaction — so none
        // can out-rank the correction learned below. Correction rules self-correct
        // separately, when the key is re-learned.
        $this->learner->forgetFromAiRules($transaction);

        return $this->learner->learnFromCorrection($transaction, $newCategoryId);
    }
}
