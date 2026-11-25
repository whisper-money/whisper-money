<?php

namespace App\Services\Transactions\LegacySplits;

use App\Enums\CategorySource;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Services\Budgets\SplitBudgetRows;
use App\Services\TransactionSplitter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns a transaction split the fork's old way (lines in `transaction_splits`)
 * into upstream's split: one part per line, the original soft-deleted, as
 * {@see TransactionSplitter} leaves a split. And back again.
 *
 * It writes quietly: no model event fires, so no automation rule, AI
 * categorization, budget job, email or notification runs. Whatever those
 * would have set is set here, and the budget rows move instead of being
 * rebuilt ({@see SplitBudgetRows}), so every period keeps what it counted.
 *
 * @phpstan-import-type FallbackRow from SplitBudgetRows
 *
 * @phpstan-type Converted array{parts: int, moved: int, fallback: list<FallbackRow>}
 */
class LegacySplitConverter
{
    public const string SKIP_TRASHED = 'in the trash';

    public const string SKIP_PART = 'a part of a split';

    public const string SKIP_FEW_LINES = 'fewer than 2 lines (transactions:audit-splits reports it)';

    public const string SKIP_UNBALANCED = 'lines do not add up to the amount (transactions:audit-splits reports it)';

    public function __construct(private readonly SplitBudgetRows $budgetRows) {}

    /**
     * Every transaction still carrying lines, live or trashed, sorted into
     * the ones to convert and the ones left alone.
     */
    public function plan(?string $userId): LegacySplitPlan
    {
        $originals = new Collection;
        $skipped = [];
        $alreadyConverted = 0;

        $this->withLines($userId, Transaction::query()->withTrashed())
            ->each(function (Transaction $transaction) use (&$originals, &$skipped, &$alreadyConverted): void {
                $verdict = self::verdict($transaction);

                match ($verdict) {
                    null => $originals->push($transaction),
                    'converted' => $alreadyConverted++,
                    default => $skipped[$verdict][] = $transaction->id,
                };
            });

        return new LegacySplitPlan($originals, $skipped, $alreadyConverted);
    }

    /**
     * Every split this conversion made: a trashed original that still has
     * its lines and live parts. A split made the new way has no lines, so it
     * is never here.
     */
    public function undoPlan(?string $userId): LegacySplitPlan
    {
        $originals = $this->withLines($userId, Transaction::query()->onlyTrashed()->whereHas('splitParts'));

        return new LegacySplitPlan($originals);
    }

    /**
     * Replace one original with its parts, in its lines' order.
     *
     * @return Converted
     */
    public function convert(Transaction $original): array
    {
        return Transaction::withoutEvents(fn (): array => $this->replaceWithParts($original));
    }

    /**
     * Put a converted original back on its lines: its parts' budget rows come
     * back to it and the parts go for good, with their labels.
     *
     * @return int the budget rows moved back
     */
    public function undo(Transaction $original): int
    {
        return Transaction::withoutEvents(function () use ($original): int {
            $parts = $original->splitParts()->get();
            $moved = $this->budgetRows->toOriginal($original, $parts);

            $parts->each(fn (Transaction $part) => $part->forceDelete());
            // Saved, so updated_at moves and the offline sync sends it.
            $original->restore();

            return $moved;
        });
    }

    /**
     * @return Converted
     */
    private function replaceWithParts(Transaction $original): array
    {
        $labelIds = DB::table('label_transaction')->where('transaction_id', $original->id)->pluck('label_id')->all();
        $now = $original->freshTimestamp();

        $parts = $original->splits->map(fn (TransactionSplit $line): Transaction => $this->createPart($original, $line, $labelIds, $now));
        $budgetRows = $this->budgetRows->toParts($original, $parts);

        // Soft-deleting also stamps updated_at, so the offline sync picks the
        // original up together with its parts.
        $original->delete();

        return ['parts' => $parts->count(), 'moved' => $budgetRows['moved'], 'fallback' => $budgetRows['fallback']];
    }

    /**
     * Null when the row converts; 'converted' when an earlier run converted
     * it; otherwise why it is left alone.
     */
    private static function verdict(Transaction $transaction): ?string
    {
        if ($transaction->isSplitPart()) {
            return self::SKIP_PART;
        }

        if ($transaction->trashed()) {
            return $transaction->splitParts()->exists() ? 'converted' : self::SKIP_TRASHED;
        }

        if ($transaction->splits->count() < 2) {
            return self::SKIP_FEW_LINES;
        }

        return (int) $transaction->splits->sum('amount') === $transaction->amount ? null : self::SKIP_UNBALANCED;
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return Collection<int, Transaction>
     */
    private function withLines(?string $userId, Builder $query): Collection
    {
        return $query
            ->whereHas('splits')
            ->when($userId !== null, fn (Builder $query) => $query->where('user_id', $userId))
            ->with(['splits', 'account', 'user:id,currency_code'])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * One line as a part. Saved quietly, so what the model events would fill
     * in (the space, the source date) is copied here, and `created_at` stays
     * the original's: the part is not new money. `updated_at` is now, so the
     * offline sync sends it.
     *
     * @param  list<string>  $labelIds  every label on the original, trashed ones included
     */
    private function createPart(Transaction $original, TransactionSplit $line, array $labelIds, Carbon $now): Transaction
    {
        $part = TransactionSplitter::newPart($original, $line->amount, $line->category_id)->forceFill([
            'space_id' => $original->space_id,
            'source_date' => $original->source_date,
            'category_source' => CategorySource::Manual,
            'created_at' => $original->created_at,
            'updated_at' => $now,
        ]);

        $part->save();
        $part->labels()->attach($labelIds);

        return $part;
    }

    /**
     * The user the --user option names, by id or email; null when none does.
     */
    public static function resolveUserId(string $idOrEmail): ?string
    {
        return User::query()->whereKey($idOrEmail)->orWhere('email', $idOrEmail)->value('id');
    }
}
