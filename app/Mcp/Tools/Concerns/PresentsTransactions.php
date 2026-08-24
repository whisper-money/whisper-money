<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Label;
use App\Models\Transaction;

/**
 * The transaction row every transaction tool returns, read and write alike, so
 * an agent sees the same shape wherever the row came from.
 */
trait PresentsTransactions
{
    /**
     * @return array<string, mixed>
     */
    protected function presentTransaction(Transaction $transaction): array
    {
        $transaction->loadMissing(['account:id,name', 'category:id,name', 'labels:id,name']);

        return [
            'id' => $transaction->id,
            'date' => $transaction->transaction_date->toDateString(),
            'description' => $transaction->description,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency_code,
            'category_id' => $transaction->category_id,
            'category' => $transaction->category?->name,
            'category_source' => $transaction->category_source?->value,
            'account_id' => $transaction->account_id,
            'account' => $transaction->account?->name,
            'source' => $transaction->source->value,
            'creditor_name' => $transaction->creditor_name,
            'debtor_name' => $transaction->debtor_name,
            // Set when this row is one part of a split. Every part of the same
            // split carries the same value, and merge_transaction_splits takes
            // any one of them.
            'split_parent_id' => $transaction->split_parent_id,
            'labels' => $transaction->labels
                ->map(fn (Label $label): array => ['id' => $label->id, 'name' => $label->name])
                ->values()
                ->all(),
        ];
    }
}
