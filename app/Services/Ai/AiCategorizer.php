<?php

namespace App\Services\Ai;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Orchestrates both tiers of AI auto-categorization for a set of transactions:
 * label each one (tier 1) and then learn a rule from every confident,
 * unambiguous result (tier 2). Shared by the real-time listener and the backfill
 * command.
 */
class AiCategorizer
{
    public function __construct(
        private readonly CategorizeTransactions $categorizer,
        private readonly AiRuleLearner $learner,
    ) {}

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return list<CategorizationOutcome>
     */
    public function run(User $user, Collection $transactions): array
    {
        $outcomes = $this->categorizer->forTransactions($user, $transactions);

        foreach ($outcomes as $outcome) {
            $this->learner->learn($outcome);
        }

        return $outcomes;
    }
}
