<?php

namespace App\Services\Imports;

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Hard-deletes accounts with every movement (trashed ones included) and
 * every balance on them: what "start from scratch" does to the user's manual
 * accounts, and what undoing an import does to the accounts it created.
 *
 * Rows go a chunk of ids at a time, each chunk its own short statement, so a
 * decade of movements never sits in one long-running delete. Every step only
 * deletes what is still there, so a run that stopped halfway can run again.
 */
class AccountPurger
{
    /** How many rows one delete statement takes. */
    private const CHUNK = 1000;

    /**
     * @param  Collection<int, string>  $accountIds
     * @return int the movements deleted with them
     */
    public function purge(Collection $accountIds): int
    {
        if ($accountIds->isEmpty()) {
            return 0;
        }

        $transactions = $this->deleteInChunks(Transaction::withTrashed()->whereIn('account_id', $accountIds));
        $this->deleteInChunks(AccountBalance::query()->whereIn('account_id', $accountIds));
        Account::withTrashed()->whereIn('id', $accountIds)->forceDelete();

        return $transactions;
    }

    /**
     * Delete what a query finds, a chunk of ids at a time, for good: a raw
     * delete, with no soft delete and no model events (the database cascades
     * take care of labels, budget snapshots and split parts).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return int the rows deleted
     */
    public function deleteInChunks(Builder $query): int
    {
        $model = $query->getModel();
        $deleted = 0;

        do {
            $ids = (clone $query)->limit(self::CHUNK)->pluck($model->getKeyName());

            if ($ids->isNotEmpty()) {
                $deleted += $model->newQueryWithoutScopes()->whereKey($ids->all())->toBase()->delete();
            }
        } while ($ids->count() === self::CHUNK);

        return $deleted;
    }
}
