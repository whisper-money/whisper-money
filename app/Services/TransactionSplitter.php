<?php

namespace App\Services;

use App\Enums\CategorySource;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Splitting a transaction into parts, and putting it back together. Every
 * caller — the web dialog, the MCP tools and `/api/v1` — comes through here,
 * so the invariants live in one place.
 *
 * The original row never goes away: it is soft-deleted, which drops it out of
 * every list, every total and every budget in one move, while keeping the
 * `dedup_fingerprint` the bank sync looks for (that lookup reads trashed rows)
 * so the next sync does not re-create what the user just split.
 */
class TransactionSplitter
{
    public const MAX_PARTS = 20;

    private const string NotASplitPart = 'That transaction is not part of a split, so there is nothing to merge.';

    /**
     * The shape of a split request, shared by the `split_transaction` MCP tool
     * and `POST /api/v1/transactions/{id}/split`. Each caller adds its own
     * check that the category and label ids are in the space.
     *
     * @return array<string, list<string>>
     */
    public static function partRules(): array
    {
        return [
            'splits' => ['required', 'array', 'min:2', 'max:'.self::MAX_PARTS],
            'splits.*.amount' => ['required', 'integer'],
            'splits.*.category_id' => ['nullable', 'string'],
            'splits.*.label_ids' => ['nullable', 'array'],
            'splits.*.label_ids.*' => ['required', 'string'],
        ];
    }

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
        self::throwIfError('transaction_id', self::splitPartError($transaction));
        self::throwIfError('splits', self::partsError($transaction, $parts));

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
            throw ValidationException::withMessages(['transaction_id' => self::NotASplitPart]);
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
     * Why the transaction cannot be split, or null when it can: a part of a
     * split is not split again.
     */
    public static function splitPartError(Transaction $transaction): ?string
    {
        return $transaction->isSplitPart()
            ? 'That transaction is already one part of a split. Merge the split back before splitting again.'
            : null;
    }

    /**
     * Why $parts cannot replace the transaction, or null when they can. These
     * are the two invariants that keep a split honest: the parts add up to the
     * original, and every part moves money the same way it did. Amounts are
     * typed unsigned in the UI and given the original's sign there, so this is
     * what makes both true for every other caller.
     *
     * @param  list<array<string, mixed>>  $parts
     */
    public static function partsError(Transaction $transaction, array $parts): ?string
    {
        if (count($parts) < 2 || count($parts) > self::MAX_PARTS) {
            return 'A split needs between 2 and '.self::MAX_PARTS.' parts.';
        }

        $amounts = array_map(static fn (array $part): int => (int) ($part['amount'] ?? 0), $parts);

        if (in_array(0, $amounts, true)) {
            return 'Every part of a split needs an amount.';
        }

        if (array_sum($amounts) !== $transaction->amount) {
            return 'The parts must add up to the original amount of '.$transaction->amount.' (in minor units).';
        }

        $movesTheSameWay = static fn (int $amount): bool => ($amount > 0) === ($transaction->amount > 0);

        return count(array_filter($amounts, $movesTheSameWay)) === count($amounts)
            ? null
            : 'Every part must move money the same way as the original.';
    }

    /**
     * Why $part cannot be merged back, or null when it can: only a part of a
     * split has an original to go back to.
     */
    public static function mergeError(Transaction $part): ?string
    {
        return $part->splitParent()->withTrashed()->exists() ? null : self::NotASplitPart;
    }

    /**
     * @throws ValidationException
     */
    private static function throwIfError(string $field, ?string $error): void
    {
        if ($error !== null) {
            throw ValidationException::withMessages([$field => $error]);
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
