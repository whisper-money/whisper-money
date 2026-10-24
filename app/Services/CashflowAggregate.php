<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Collection;

/**
 * One group of transactions that every cashflow figure treats the same way,
 * added up in SQL by {@see CashflowAggregateQuery}.
 *
 * `$transaction` stands in for the whole group: it carries the account, the
 * category and, as its `amount`, only the group's sign, so the group is
 * classified by the very predicates a single row would be
 * ({@see Transaction::isIncomeSide()}, {@see Transaction::isSetAsideAccountLeg()}
 * and friends). It is never saved. The sign is only kept apart for rows with
 * no category, the one place a predicate reads it; a categorized stand-in's
 * amount is 0. The money is in `$amount`.
 */
final readonly class CashflowAggregate
{
    /**
     * @param  int  $amount  the group's total in the user's currency, reduced to
     *                       the owner's share of the account
     * @param  string|null  $bucket  the bucket the query grouped by, if any
     */
    public function __construct(
        public Transaction $transaction,
        public int $amount,
        public ?string $bucket = null,
    ) {}

    /**
     * @param  Collection<int, self>  $aggregates
     */
    public static function total(Collection $aggregates): int
    {
        return $aggregates->sum(fn (self $aggregate): int => $aggregate->amount);
    }
}
