<?php

namespace App\Http\Controllers;

use App\Enums\CategorySource;
use App\Http\Requests\SplitTransactionRequest;
use App\Models\Transaction;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Splitting a transaction into parts, and putting it back together.
 *
 * The original row never goes away: it is soft-deleted, which drops it out of
 * every list, every total and every budget in one move, while keeping the
 * `dedup_fingerprint` the bank sync looks for (that lookup reads trashed rows)
 * so the next sync does not re-create what the user just split.
 */
class TransactionSplitController extends Controller
{
    use AuthorizesRequests;

    public function store(SplitTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('update', $transaction);

        if ($transaction->isSplitPart()) {
            return response()->json([
                'message' => 'This transaction is already part of a split. Merge it back before splitting again.',
            ], 422);
        }

        $splits = DB::transaction(
            fn (): ?Collection => $this->splitOnce($transaction, $request->validated()['splits'])
        );

        if ($splits === null) {
            return response()->json([
                'message' => 'This transaction has already been split.',
            ], 422);
        }

        return response()->json(['data' => $splits->values()], 201);
    }

    public function destroy(Transaction $transaction): JsonResponse
    {
        $this->authorize('update', $transaction);

        $original = $transaction->splitParent()->withTrashed()->first();

        if ($original === null) {
            return response()->json([
                'message' => 'This transaction is not part of a split.',
            ], 422);
        }

        DB::transaction(function () use ($original): void {
            // Soft-deleting each part fires the model event that takes it back
            // out of its budgets; restoring the original fires the one that
            // puts the original back in.
            $original->splits->each->delete();
            $original->restore();
        });

        return response()->json([
            'data' => $original->fresh()->load('labels'),
        ]);
    }

    /**
     * Replace the original with its parts, or null when someone got there first.
     *
     * Two clicks on the same transaction must not each produce a full set of
     * parts off the same money, so the row is locked for the length of the
     * split: the loser finds it either gone (soft-deleted) or already split.
     *
     * @param  list<array<string, mixed>>  $splits
     * @return Collection<int, Transaction>|null
     */
    private function splitOnce(Transaction $transaction, array $splits): ?Collection
    {
        $original = Transaction::query()
            ->whereKey($transaction->getKey())
            ->lockForUpdate()
            ->first();

        if ($original === null || $original->splits()->exists()) {
            return null;
        }

        $parts = collect($splits)->map(
            fn (array $split): Transaction => $this->createSplit($original, $split)
        );

        $original->delete();

        return $parts;
    }

    /**
     * One part of a split: the original in every respect except the amount, the
     * category and the labels. The bank's own identifiers stay behind on the
     * original — a part is our row, not something the bank sent us.
     *
     * @param  array<string, mixed>  $split
     */
    private function createSplit(Transaction $original, array $split): Transaction
    {
        $part = $original->replicate([
            'dedup_fingerprint',
            'external_transaction_id',
            'raw_data',
            'category_id',
            'category_source',
            'ai_confidence',
            'categorized_by_rule_id',
            'ai_suggested_category_id',
            'ai_suggested_category_at',
            'ai_model',
        ]);

        $categoryId = $split['category_id'] ?? null;

        $part->fill([
            'split_parent_id' => $original->id,
            'amount' => (int) $split['amount'],
            'category_id' => $categoryId,
            'category_source' => $categoryId === null ? null : CategorySource::Manual->value,
        ]);

        $part->save();
        $part->labels()->sync($split['label_ids'] ?? []);

        return $part->load('labels');
    }
}
