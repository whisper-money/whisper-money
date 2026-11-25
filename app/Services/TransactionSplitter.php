<?php

namespace App\Services;

use App\Enums\CategorySource;
use App\Jobs\ReassignTransactionsToBudgets;
use App\Models\Transaction;
use App\Services\Budgets\SplitBudgetRows;
use Closure;
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
 *
 * A transaction still filed under the old split lines (`transaction_splits`)
 * is not split this way until it is converted: lines and parts on the same
 * money would count it twice.
 */
class TransactionSplitter
{
    public const MAX_PARTS = 20;

    private const string NotASplitPart = 'That transaction is not part of a split, so there is nothing to merge.';

    public function __construct(private readonly SplitBudgetRows $budgetRows) {}

    /**
     * The shape of a split request, shared by the `split_transaction` MCP tool
     * and `POST /api/v1/transactions/{id}/split`. Each caller adds its own
     * check that the category and label ids are in the space.
     *
     * @return array<string, list<mixed>>
     */
    public static function partRules(): array
    {
        return [
            'splits' => ['required', 'array', 'min:2', 'max:'.self::MAX_PARTS],
            'splits.*' => ['array:amount,category_id,label_ids'],
            'splits.*.amount' => ['required', 'integer'],
            'splits.*.category_id' => ['nullable', 'string'],
            'splits.*.label_ids' => ['nullable', 'array', 'max:100', self::noLabelTwiceInAPart()],
            'splits.*.label_ids.*' => ['required', 'string'],
        ];
    }

    /**
     * The messages that go with {@see self::partRules()}: a part with a field
     * it does not take would otherwise be answered "must be an array".
     *
     * @return array<string, string>
     */
    public static function partMessages(): array
    {
        return [
            'splits.*.array' => 'A part takes only amount, category_id and label_ids.',
        ];
    }

    /**
     * A label may sit on several parts of the same split, so it is only
     * refused twice within one part. Laravel's `distinct` rule would compare
     * across every part.
     */
    private static function noLabelTwiceInAPart(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            $ids = array_map(static fn (mixed $id): string => mb_strtolower((string) $id), array_filter((array) $value, is_scalar(...)));

            if (count($ids) !== count(array_unique($ids))) {
                $fail('A label can only be given once per part.');
            }
        };
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
        self::throwIfError('transaction_id', self::splitPartError($transaction) ?? self::legacyLinesError($transaction));
        self::throwIfError('splits', self::partsError($transaction, $parts));

        $created = DB::transaction(fn (): ?Collection => $this->replaceWithParts($transaction, $parts));

        if ($created === null) {
            throw ValidationException::withMessages([
                'splits' => 'This transaction has already been split. Merge it back before splitting again.',
            ]);
        }

        $this->assignToBudgets($created);

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
            // What archived budgets counted of the parts goes back to the
            // original first: they take no new rows, so the reassignment
            // below could not put it back.
            $this->budgetRows->toOriginal($original, $original->splitParts, archivedOnly: true);
            // Soft-deleting each part fires the model event that takes it back
            // out of its budgets. The original comes back quietly and is put
            // back in its budgets below, once the merge has committed.
            $original->splitParts->each->delete();
            $original->restoreQuietly();
            // A split converted from the old lines comes back as one row:
            // lines left on it would count it a second way and keep it from
            // being split again. It keeps whatever category it carried.
            $original->splits()->delete();
        });

        $this->assignToBudgets(collect([$original]));

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
     * Why a transaction still filed under the old split lines cannot be split
     * into parts, or null when it has none.
     */
    public static function legacyLinesError(Transaction $transaction): ?string
    {
        return $transaction->splits()->exists()
            ? 'This transaction is still split into lines the old way. It has to be converted to the new split model before it can be split again.'
            : null;
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

        if ($original === null || $original->splitParts()->exists()) {
            return null;
        }

        // Checked again on the row as it is under the lock: one edited since
        // the caller read it (its amount, say) must not be replaced by parts
        // that add up to what it used to be.
        self::throwIfError('transaction_id', self::splitPartError($original) ?? self::legacyLinesError($original));
        self::throwIfError('splits', self::partsError($original, $parts));

        $created = collect($parts)->map(
            fn (array $part): Transaction => $this->createPart($original, $part)
        );

        // Archived budgets take no new rows, so what they counted of the
        // original moves to the parts before the original's delete takes its
        // rows away. Live budgets are reassigned once this has committed.
        $this->budgetRows->toParts($original, $created, archivedOnly: true);
        $original->delete();

        return $created;
    }

    /**
     * One part of a split: the original in every respect except the amount, the
     * category and the labels. The bank's own identifiers stay behind on the
     * original — a part is our row, not something the bank sent us. The
     * description and notes travel with their device-encryption IVs. Not saved:
     * a split saves it with its model events, the conversion of the old split
     * lines without them.
     */
    public static function newPart(Transaction $original, int $amount, ?string $categoryId): Transaction
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

        // A copy also carries the original's loaded relations (its lines, say).
        return $part->unsetRelations()->fill([
            'split_parent_id' => $original->id,
            'amount' => $amount,
            'category_id' => $categoryId,
            'category_source' => $categoryId === null ? null : CategorySource::Manual->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $part
     */
    private function createPart(Transaction $original, array $part): Transaction
    {
        $created = self::newPart($original, (int) $part['amount'], $part['category_id'] ?? null);

        $created->save();
        // On top of what an automation rule tagged it with while it was saved,
        // as for any new transaction (TransactionWriter::create()).
        $created->labels()->syncWithoutDetaching($part['label_ids'] ?? []);

        return $created->load('labels');
    }

    /**
     * Put the rows a split or a merge leaves into their budgets, once, after it
     * has committed: a part then carries its labels, so a budget tracking one
     * of them counts it whichever order the queue runs in. The listener that
     * assigns a new row leaves a part to this pass
     * (AssignTransactionToBudget::shouldQueue()), and the
     * original comes back without the update event that would assign it.
     * Parts keep the original's `import_id`, so undoing the import still takes
     * them away. A split or a merge is not new spending, so no budget emails.
     *
     * @param  Collection<int, Transaction>  $transactions
     */
    private function assignToBudgets(Collection $transactions): void
    {
        ReassignTransactionsToBudgets::dispatch($transactions->pluck('id')->values()->all(), notify: false);
    }
}
