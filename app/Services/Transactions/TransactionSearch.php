<?php

namespace App\Services\Transactions;

use App\Models\Space;
use App\Models\Transaction;
use App\Rules\StorableDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Fluent;

/**
 * The transaction filters shared by the search_transactions MCP tool and
 * `GET /api/v1/transactions`. Each caller adds its own ordering and limit:
 * the tool returns up to its `limit` by date, the endpoint pages through the
 * results with a cursor.
 */
class TransactionSearch
{
    /**
     * The rules every caller validates the filters with.
     *
     * @return array<string, array<mixed>>
     */
    public static function rules(): array
    {
        return [
            'from' => ['sometimes', 'date', new StorableDate],
            'to' => ['sometimes', 'date', new StorableDate],
        ];
    }

    /**
     * The transactions of $space matching $filters: `query` (matched against
     * the description and the counterparty names), `account_id`,
     * `category_id`, `label_ids` (any of them, already checked to be in the
     * space), `from`, `to`, `min_amount` and `max_amount`.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Transaction>
     */
    public function query(Space $space, array $filters): Builder
    {
        $filters = new Fluent($filters);
        $labelIds = (array) $filters->get('label_ids', []);

        return Transaction::query()
            ->forSpace($space)
            ->when($filters->string('query')->toString() !== '', function (Builder $query) use ($filters): void {
                $term = '%'.$filters->string('query')->toString().'%';
                $query->where(function (Builder $q) use ($term): void {
                    $q->where('description', 'like', $term)
                        ->orWhere('creditor_name', 'like', $term)
                        ->orWhere('debtor_name', 'like', $term);
                });
            })
            ->when($filters->string('account_id')->toString() !== '', fn (Builder $query) => $query->where('account_id', $filters->string('account_id')->toString()))
            ->when($filters->string('category_id')->toString() !== '', fn (Builder $query) => $query->where('category_id', $filters->string('category_id')->toString()))
            ->when($labelIds !== [], fn (Builder $query) => $query->whereHas('labels', fn (Builder $q) => $q->whereIn('labels.id', $labelIds)))
            ->transactionDateWithin($filters->string('from')->toString(), $filters->string('to')->toString())
            ->when($filters->has('min_amount'), fn (Builder $query) => $query->where('amount', '>=', $filters->integer('min_amount')))
            ->when($filters->has('max_amount'), fn (Builder $query) => $query->where('amount', '<=', $filters->integer('max_amount')));
    }
}
