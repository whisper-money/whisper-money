<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionSplit;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Effective category attribution: an unsplit transaction is attributed to its
 * own category, a split transaction to its lines. The category breakdowns read
 * through here — either the SQL {@see self::query()} or the collection
 * {@see self::expand()} — so a split is never double-counted or dropped. The two
 * encarnations share the "iterate lines vs the row itself" rule but not code:
 * SQL sums raw rows, expand() needs hydrated models (account/category/labels)
 * for currency conversion and side classification.
 */
class TransactionAllocations
{
    /**
     * SQL allocation rows for a user's transactions in a period — one row per
     * unsplit transaction (its own category + amount) and one per split line
     * (the line's category + amount). Soft-deleted transactions are excluded.
     * Returned as a query builder so callers can alias it as `transactions` and
     * keep their existing joins/aggregations unchanged.
     */
    public static function query(string $userId, Carbon $from, Carbon $to): QueryBuilder
    {
        $unsplit = DB::table('transactions as t')
            ->select('t.category_id', 't.amount', 't.transaction_date')
            ->whereNull('t.deleted_at')
            ->where('t.user_id', $userId)
            ->whereBetween('t.transaction_date', [$from, $to])
            ->whereNotExists(function (QueryBuilder $query): void {
                $query->select(DB::raw(1))
                    ->from('transaction_splits as s')
                    ->whereColumn('s.transaction_id', 't.id');
            });

        $lines = DB::table('transaction_splits as s')
            ->join('transactions as t', 't.id', '=', 's.transaction_id')
            ->select('s.category_id', 's.amount', 't.transaction_date')
            ->whereNull('t.deleted_at')
            ->where('t.user_id', $userId)
            ->whereBetween('t.transaction_date', [$from, $to]);

        return $unsplit->unionAll($lines);
    }

    /**
     * Expand a loaded transaction collection into effective allocations: a split
     * transaction becomes one synthetic Transaction per line (carrying the line's
     * amount + category + labels while keeping the parent's account/currency/date
     * for FX and side classification); unsplit transactions pass through
     * unchanged.
     *
     * Callers must eager-load `splits.category` (and `splits.labels`/`account`
     * where they read those). Synthetic line instances deliberately keep the
     * parent's `id`, so consumers must aggregate by `category_id`/labels, never
     * `keyBy('id')`/`unique('id')` (that would collapse a split's lines).
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return Collection<int, Transaction>
     */
    public static function expand(Collection $transactions): Collection
    {
        return $transactions->flatMap(function (Transaction $transaction): array {
            $splits = $transaction->relationLoaded('splits') ? $transaction->splits : null;

            if ($splits === null || $splits->isEmpty()) {
                return [$transaction];
            }

            return $splits->map(function (TransactionSplit $line) use ($transaction): Transaction {
                $allocation = (new Transaction)->setRawAttributes($transaction->getAttributes());
                $allocation->forceFill([
                    'amount' => $line->amount,
                    'category_id' => $line->category_id,
                ]);
                $allocation->setRelation('account', $transaction->relationLoaded('account') ? $transaction->account : null);
                $allocation->setRelation('category', $line->relationLoaded('category') ? $line->category : null);

                if ($line->relationLoaded('labels')) {
                    $allocation->setRelation('labels', $line->labels);
                }

                return $allocation;
            })->all();
        })->values();
    }
}
