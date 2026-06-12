<?php

namespace App\Services\Ai;

use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\Contracts\TransactionMatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class UncategorizedTransactionMatcher implements TransactionMatcher
{
    /**
     * Fields a suggested rule is allowed to match against (server-readable,
     * never the encrypted description/notes blobs).
     */
    public const ALLOWED_FIELDS = ['description', 'creditor_name', 'debtor_name'];

    public function total(User $user): int
    {
        return $this->baseQuery($user)->count();
    }

    public function countMatching(User $user, string $field, string $operator, string $token): int
    {
        $query = $this->matchQuery($user, $field, $operator, $token);

        return $query === null ? 0 : $query->count();
    }

    public function matching(User $user, string $field, string $operator, string $token, ?int $limit = null): Collection
    {
        $query = $this->matchQuery($user, $field, $operator, $token);

        if ($query === null) {
            return new Collection;
        }

        $query->orderByDesc('transaction_date')->orderByDesc('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * @return Builder<Transaction>
     */
    private function baseQuery(User $user): Builder
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->whereNull('category_id')
            ->whereNull('description_iv');
    }

    /**
     * @return Builder<Transaction>|null
     */
    private function matchQuery(User $user, string $field, string $operator, string $token): ?Builder
    {
        if (! in_array($field, self::ALLOWED_FIELDS, true) || trim($token) === '') {
            return null;
        }

        $query = $this->baseQuery($user);
        $token = mb_strtolower(trim($token));

        if ($operator === 'equals') {
            return $query->whereRaw("LOWER({$field}) = ?", [$token]);
        }

        return $query->whereRaw("LOWER({$field}) LIKE ?", ['%'.$this->escapeLike($token).'%']);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
