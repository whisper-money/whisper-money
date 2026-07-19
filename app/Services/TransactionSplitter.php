<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class TransactionSplitter
{
    /**
     * Replace a transaction's split lines. The lines become the source of truth
     * for its category attribution: the transaction itself is left with no
     * category and no labels (both now live on the lines). Amounts are assumed
     * validated (same sign as the transaction, summing to its total).
     *
     * @param  array<int, array{category_id?: ?string, amount: int|string, label_ids?: array<int, string>}>  $lines
     */
    public function apply(Transaction $transaction, array $lines): void
    {
        DB::transaction(function () use ($transaction, $lines): void {
            $transaction->labels()->detach();
            $transaction->splits()->delete();

            foreach ($lines as $line) {
                $split = $transaction->splits()->create([
                    'category_id' => $line['category_id'] ?? null,
                    'amount' => (int) $line['amount'],
                ]);

                $labelIds = $line['label_ids'] ?? [];

                if ($labelIds !== []) {
                    $split->labels()->sync($labelIds);
                }
            }

            $transaction->forceFill([
                'category_id' => null,
                'category_source' => null,
                'ai_confidence' => null,
                'categorized_by_rule_id' => null,
                'ai_suggested_category_id' => null,
                'ai_suggested_category_at' => null,
            ])->save();
        });
    }

    /**
     * Collapse a split back to a single-category transaction by dropping its
     * lines. The caller restores the single category via the normal update path.
     */
    public function remove(Transaction $transaction): void
    {
        $transaction->splits()->delete();
    }
}
