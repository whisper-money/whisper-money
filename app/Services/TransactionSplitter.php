<?php

namespace App\Services;

use App\Enums\CategorySource;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Splitting a transaction into parts, and putting it back together. Every
 * caller — the web dialog and the MCP tools — comes through here, so the
 * invariants live in one place.
 *
 * The original row never goes away: it is soft-deleted, which drops it out of
 * every list, every total and every budget in one move, while keeping the
 * `dedup_fingerprint` the bank sync looks for (that lookup reads trashed rows)
 * so the next sync does not re-create what the user just split.
 */
class TransactionSplitter
{
    public const MAX_PARTS = 20;

    /**
     * Replace a transaction with its parts.
     *
     * @param  list<array<string, mixed>>  $parts
     * @return Collection<int, Transaction>
     *
     * @throws ValidationException
     */
    public function split(Transaction $transaction, array $parts): Collection
    {
        $this->assertPartsAddUp($transaction, $parts);

        $created = DB::transaction(fn (): ?Collection => $this->replaceWithParts($transaction, $parts));

        if ($created === null) {
            throw ValidationException::withMessages([
                'splits' => 'This transaction has already been split. Merge it back before splitting again.',
            ]);
        }

        return $created;
    }

    /**
     * Merge a split back: every part goes away and the original comes back with
     * the category it had before. Takes any one of the parts.
     *
     * @throws ValidationException
     */
    public function merge(Transaction $part): Transaction
    {
        $original = $part->splitParent()->withTrashed()->first();

        if ($original === null) {
            throw ValidationException::withMessages([
                'transaction_id' => 'That transaction is not part of a split, so there is nothing to merge.',
            ]);
        }

        DB::transaction(function () use ($original): void {
            // Soft-deleting each part fires the model event that takes it back
            // out of its budgets; restoring the original fires the one that
            // puts the original back in.
            $original->splits->each->delete();
            $original->restore();
        });

        return $original->fresh();
    }

    /**
     * The two invariants that keep a split honest: the parts add up to the
     * original, and every part moves money the same way it did. Amounts are
     * typed unsigned in the UI and given the original's sign there, so this is
     * what makes both true for every other caller.
     *
     * @param  list<array<string, mixed>>  $parts
     *
     * @throws ValidationException
     */
    private function assertPartsAddUp(Transaction $transaction, array $parts): void
    {
        if ($transaction->isSplitPart()) {
            throw ValidationException::withMessages([
                'transaction_id' => 'That transaction is already one part of a split. Merge the split back before splitting again.',
            ]);
        }

        if (count($parts) < 2 || count($parts) > self::MAX_PARTS) {
            throw ValidationException::withMessages([
                'splits' => 'A split needs between 2 and '.self::MAX_PARTS.' parts.',
            ]);
        }

        $amounts = array_map(static fn (array $part): int => (int) ($part['amount'] ?? 0), $parts);

        if (in_array(0, $amounts, true)) {
            throw ValidationException::withMessages([
                'splits' => 'Every part of a split needs an amount.',
            ]);
        }

        if (array_sum($amounts) !== $transaction->amount) {
            throw ValidationException::withMessages([
                'splits' => 'The parts must add up to the original amount of '.$transaction->amount.' (in minor units).',
            ]);
        }

        $movesTheSameWay = static fn (int $amount): bool => ($amount > 0) === ($transaction->amount > 0);

        if (count(array_filter($amounts, $movesTheSameWay)) !== count($amounts)) {
            throw ValidationException::withMessages([
                'splits' => 'Every part must move money the same way as the original.',
            ]);
        }
    }

    /**
     * Replace the original with its parts, or null when someone got there first.
     *
     * Two splits of the same transaction must not each produce a full set of
     * parts off the same money, so the row is locked for the length of the
     * split: the loser finds it either gone (soft-deleted) or already split.
     *
     * @param  list<array<string, mixed>>  $parts
     * @return Collection<int, Transaction>|null
     */
    private function replaceWithParts(Transaction $transaction, array $parts): ?Collection
    {
        $original = Transaction::query()
            ->whereKey($transaction->getKey())
            ->lockForUpdate()
            ->first();

        if ($original === null || $original->splits()->exists()) {
            return null;
        }

        $created = collect($parts)->map(
            fn (array $part): Transaction => $this->createPart($original, $part)
        );

        $original->delete();

        return $created;
    }

    /**
     * One part of a split: the original in every respect except the amount, the
     * category and the labels. The bank's own identifiers stay behind on the
     * original — a part is our row, not something the bank sent us.
     *
     * @param  array<string, mixed>  $part
     */
    private function createPart(Transaction $original, array $part): Transaction
    {
        $created = $original->replicate([
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

        $categoryId = $part['category_id'] ?? null;

        $created->fill([
            'split_parent_id' => $original->id,
            'amount' => (int) $part['amount'],
            'category_id' => $categoryId,
            'category_source' => $categoryId === null ? null : CategorySource::Manual->value,
        ]);

        $created->save();
        $created->labels()->sync($part['label_ids'] ?? []);

        return $created->load('labels');
    }
}
