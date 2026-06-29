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

    public function countMatchingAny(User $user, array $conditions): int
    {
        $query = $this->anyQuery($user, $conditions);

        return $query === null ? 0 : $query->count();
    }

    public function matchingAny(User $user, array $conditions, ?int $limit = null): Collection
    {
        $query = $this->anyQuery($user, $conditions);

        if ($query === null) {
            return new Collection;
        }

        $query->orderByDesc('transaction_date')->orderByDesc('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    public function countMatchingAll(User $user, array $conditions): int
    {
        $query = $this->allQuery($user, $conditions);

        return $query === null ? 0 : $query->count();
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

    /**
     * Build a single query matching ANY of the conditions (OR). Invalid
     * conditions (unknown field or blank token) are skipped; returns null when
     * none remain.
     *
     * @param  list<array{field: string, operator: string, token: string}>  $conditions
     * @return Builder<Transaction>|null
     */
    private function anyQuery(User $user, array $conditions): ?Builder
    {
        $valid = array_values(array_filter(
            $conditions,
            fn (array $condition): bool => in_array($condition['field'], self::ALLOWED_FIELDS, true)
                && trim($condition['token']) !== '',
        ));

        if ($valid === []) {
            return null;
        }

        return $this->baseQuery($user)->where(function (Builder $builder) use ($valid): void {
            foreach ($valid as $condition) {
                $field = $condition['field'];
                $token = mb_strtolower(trim($condition['token']));

                if ($condition['operator'] === 'equals') {
                    $builder->orWhereRaw("LOWER({$field}) = ?", [$token]);

                    continue;
                }

                $builder->orWhereRaw("LOWER({$field}) LIKE ?", ['%'.$this->escapeLike($token).'%']);
            }
        });
    }

    /**
     * Build a single query matching ALL of the conditions (AND). Invalid
     * conditions (unknown field or blank token) are skipped; returns null when
     * none remain.
     *
     * @param  list<array{field: string, operator: string, token: string}>  $conditions
     * @return Builder<Transaction>|null
     */
    private function allQuery(User $user, array $conditions): ?Builder
    {
        $valid = array_values(array_filter(
            $conditions,
            fn (array $condition): bool => in_array($condition['field'], self::ALLOWED_FIELDS, true)
                && trim($condition['token']) !== '',
        ));

        if ($valid === []) {
            return null;
        }

        $query = $this->baseQuery($user);

        foreach ($valid as $condition) {
            $field = $condition['field'];
            $token = mb_strtolower(trim($condition['token']));

            if ($condition['operator'] === 'equals') {
                $query->whereRaw("LOWER({$field}) = ?", [$token]);

                continue;
            }

            $query->whereRaw("LOWER({$field}) LIKE ?", ['%'.$this->escapeLike($token).'%']);
        }

        return $query;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
