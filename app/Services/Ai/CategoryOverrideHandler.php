<?php

namespace App\Services\Ai;

use App\Enums\CategorySource;
use App\Enums\RuleOrigin;
use App\Models\AutomationRule;
use App\Models\CategoryCorrection;
use App\Models\Transaction;

/**
 * Runs when a user overrides a transaction's category. If the category being
 * replaced was assigned by AI — directly, or via an ai-owned rule — it records a
 * correction (the calibration signal) and self-heals the rule so the same wrong
 * category is not forced on future transactions from that merchant. User-owned
 * rules and manual categories are never touched.
 *
 * Must be called BEFORE the new category is written, while the transaction still
 * holds its previous categorization.
 */
class CategoryOverrideHandler
{
    public function __construct(private readonly AiRuleLearner $learner) {}

    public function record(Transaction $transaction, ?string $newCategoryId): void
    {
        if ($newCategoryId === $transaction->category_id) {
            return;
        }

        $rule = $transaction->categorized_by_rule_id !== null
            ? AutomationRule::query()->find($transaction->categorized_by_rule_id)
            : null;

        $aiRule = $rule !== null && $rule->origin === RuleOrigin::Ai;
        $aiDriven = $transaction->category_source === CategorySource::Ai || $aiRule;

        if (! $aiDriven) {
            return;
        }

        CategoryCorrection::create([
            'user_id' => $transaction->user_id,
            'transaction_id' => $transaction->id,
            'from_category_id' => $transaction->category_id,
            'to_category_id' => $newCategoryId,
            'source' => $transaction->category_source ?? CategorySource::Rule,
            'confidence' => $transaction->ai_confidence,
        ]);

        if ($aiRule) {
            $this->learner->forget($rule, $transaction);
        }
    }
}
