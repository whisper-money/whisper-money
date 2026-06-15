<?php

namespace App\Services\Ai;

use App\Models\Transaction;

/**
 * The result of asking the model to categorize a single transaction: the chosen
 * category (already resolved to a real id), the model's confidence, whether the
 * merchant is safe to generalise into a rule, and whether the label was actually
 * auto-applied (i.e. it cleared the label confidence bar).
 */
final readonly class CategorizationOutcome
{
    public function __construct(
        public Transaction $transaction,
        public string $categoryId,
        public float $confidence,
        public bool $merchantUnambiguous,
        public bool $applied,
    ) {}
}
