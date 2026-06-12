<?php

namespace App\Services\Ai\Contracts;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface TransactionMatcher
{
    /**
     * Total number of the user's uncategorized, server-readable transactions.
     */
    public function total(User $user): int;

    /**
     * Count uncategorized transactions a candidate token would match.
     */
    public function countMatching(User $user, string $field, string $operator, string $token): int;

    /**
     * The uncategorized transactions a candidate token matches.
     *
     * @return Collection<int, Transaction>
     */
    public function matching(User $user, string $field, string $operator, string $token, ?int $limit = null): Collection;
}
