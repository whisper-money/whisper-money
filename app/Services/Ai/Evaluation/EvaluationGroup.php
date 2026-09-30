<?php

namespace App\Services\Ai\Evaluation;

use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\CategoryCatalog;
use Illuminate\Support\Collection;

/**
 * One user's share of an evaluation sample: transactions the user categorized
 * by hand, whose category is the ground truth, and the catalog the models are
 * asked to choose from.
 */
final class EvaluationGroup
{
    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    public function __construct(
        public readonly User $user,
        public readonly CategoryCatalog $catalog,
        public readonly Collection $transactions,
    ) {}
}
