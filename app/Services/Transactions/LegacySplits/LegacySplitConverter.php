<?php

namespace App\Services\Transactions\LegacySplits;

use App\Console\Commands\AuditTransactionSplitsCommand;
use App\Enums\CategorySource;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Services\Budgets\SplitBudgetRows;
use App\Services\TransactionSplitter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
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
    private const string SKIP_TRASHED = 'in the trash';

    private const string SKIP_PART = 'a part of a split';

    private const string SKIP_LIVE_WITH_PARTS = 'live but already has live parts (restored after a conversion)';

    public const string SKIP_CHANGED = 'parts were changed after conversion';

    /** The reasons a conversion always reports, even when nothing falls under them. */
    public const array FIXED_SKIP_REASONS = [self::SKIP_TRASHED, self::SKIP_PART, self::SKIP_LIVE_WITH_PARTS];

    public function __construct(
        private readonly SplitBudgetRows $budgetRows,
        private readonly AuditTransactionSplitsCommand $audit,
    ) {}

    /**
     * Every transaction still carrying lines, live or trashed, sorted into
     * the ones to convert and the ones left alone. A row
     * `transactions:audit-splits` flags is left alone under the anomalies it
     * reports: nothing it can flag changes a total, so the verification would
     * pass, yet its parts would break what a split promises.
     */
    public function plan(?string $userId): LegacySplitPlan
    {
        $flagged = $this->flaggedByAudit();
        $transactions = $this->withLines($userId, Transaction::query()->withTrashed());
        $withLiveParts = array_flip($this->withLiveParts($transactions->modelKeys()));
        $originals = new Collection;
        $skipped = [];
        $alreadyConverted = 0;

        foreach ($transactions as $transaction) {
            $verdict = self::verdict($transaction, isset($withLiveParts[$transaction->id])) ?? $flagged[$transaction->id] ?? null;

            match ($verdict) {
                null => $originals->push($transaction),
                'converted' => $alreadyConverted++,
                default => $skipped[$verdict][] = $transaction->id,
            };
        }

        return new LegacySplitPlan($originals, $skipped, $alreadyConverted);
    }

    /**
     * Every split this conversion made: a trashed original that still has
     * its lines and parts, live or not. A split made the new way has no
     * lines, so it is never here. Whether its parts still match its lines is
     * for the caller to check.
     */
    public function undoPlan(?string $userId): LegacySplitPlan
    {
        $originals = $this->withLines($userId, Transaction::query()->onlyTrashed()->whereIn('id', Transaction::withTrashed()->whereNotNull('split_parent_id')->select('split_parent_id')));

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
     * Restoring stamps the original's `updated_at`, so the offline sync sends
     * it as a live row again. The parts' removal is never announced: the sync
     * reads live rows only and a client never drops a row it holds.
     *
     * @return int the budget rows moved back
     */
    public function undo(Transaction $original): int
    {
        return Transaction::withoutEvents(function () use ($original): int {
            $parts = $original->splitParts()->get();
            $moved = $this->budgetRows->toOriginal($original, $parts);

            $parts->each(fn (Transaction $part) => $part->forceDelete());
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

        // The offline sync reads live rows only, so this deletion never
        // reaches a client that already holds the original.
        $original->delete();

        return ['parts' => $parts->count(), 'moved' => $budgetRows['moved'], 'fallback' => $budgetRows['fallback']];
    }

    /**
     * The anomalies `transactions:audit-splits` reports, as one skip reason
     * per transaction id.
     *
     * @return array<string, string>
     */
    private function flaggedByAudit(): array
    {
        return collect($this->audit->audit()['anomalies'])
            ->groupBy('id')
            ->map(fn (SupportCollection $anomalies): string => 'flagged by transactions:audit-splits ('.$anomalies->pluck('code')->implode(', ').')')
            ->all();
    }

    /**
     * 'converted' when an earlier run converted the row; why it is left
     * alone when its shape alone says so; null otherwise.
     */
    private static function verdict(Transaction $transaction, bool $hasLiveParts): ?string
    {
        return match (true) {
            $transaction->isSplitPart() => self::SKIP_PART,
            $transaction->trashed() => $hasLiveParts ? 'converted' : self::SKIP_TRASHED,
            // Only a manual restore leaves a row with lines and live parts:
            // converting it again would count its money twice.
            $hasLiveParts => self::SKIP_LIVE_WITH_PARTS,
            default => null,
        };
    }

    /**
     * Which of these transactions have a live part.
     *
     * @param  array<int, string>  $transactionIds
     * @return list<string>
     */
    private function withLiveParts(array $transactionIds): array
    {
        return collect($transactionIds)
            ->chunk(1000)
            ->flatMap(fn (SupportCollection $ids) => Transaction::query()->whereIn('split_parent_id', $ids->all())->distinct()->pluck('split_parent_id'))
            ->values()
            ->all();
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
     * offline sync sends the part as a new row.
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
