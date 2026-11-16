<?php

namespace App\Services\Transactions;

use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SpaceResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Fluent;

/**
 * The filters of `GET /api/v1/transactions`, on top of the transactions list's
 * own {@see Transaction::scopeApplyFilters()}, so the API and the app agree on
 * what a filter matches. A category picks its whole subtree and matches a
 * split transaction through any of its lines; a category and labels sent
 * together are one filter, matching either. A split transaction stays one row.
 *
 * Two filters stay out of applyFilters on purpose. It reads amounts in major
 * units and multiplies them in floating point, which would miss exact minor
 * units (19.99 * 100 is 1998.9999999999998). And its search runs LIKE over the
 * stored text, which on a row encrypted on the device is ciphertext: a match
 * there would be noise, so `query` only reads text the server can read.
 */
class TransactionSearch
{
    /**
     * $user's own transactions of $space matching $filters: `query`,
     * `account_id`, `category_id`, `label_ids`, `from`, `to`, `min_amount` and
     * `max_amount`, the ids already checked.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Transaction>
     */
    public function query(User $user, Space $space, array $filters): Builder
    {
        $filters = new Fluent($filters);

        return app(SpaceResolver::class)->ownedInSpace(Transaction::query(), $user, $space)
            ->applyFilters([
                'user_id' => $user->id,
                'date_from' => $filters->get('from'),
                'date_to' => $filters->get('to'),
                'account_ids' => array_filter([$filters->get('account_id')]),
                'category_ids' => array_filter([$filters->get('category_id')]),
                'label_ids' => (array) $filters->get('label_ids', []),
            ])
            ->when($filters->has('query'), fn (Builder $query) => $this->matchReadableText($query, '%'.$filters->string('query')->toString().'%'))
            ->when($filters->has('min_amount'), fn (Builder $query) => $query->where('amount', '>=', $filters->integer('min_amount')))
            ->when($filters->has('max_amount'), fn (Builder $query) => $query->where('amount', '<=', $filters->integer('max_amount')));
    }

    /**
     * Rows whose readable text matches the LIKE pattern $term. Columns are
     * qualified, so it also holds on a query that joined other tables
     * ({@see TransactionAggregator}).
     *
     * @param  Builder<Transaction>  $query
     */
    public function matchReadableText(Builder $query, string $term): void
    {
        $query->where(fn (Builder $text) => $text
            ->where(fn (Builder $description) => $description->whereNull('transactions.description_iv')->where('transactions.description', 'like', $term))
            ->orWhere(fn (Builder $notes) => $notes->whereNull('transactions.notes_iv')->where('transactions.notes', 'like', $term))
            ->orWhere('transactions.creditor_name', 'like', $term)
            ->orWhere('transactions.debtor_name', 'like', $term));
    }
}
