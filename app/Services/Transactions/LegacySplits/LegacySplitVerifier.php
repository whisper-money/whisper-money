<?php

namespace App\Services\Transactions\LegacySplits;

use App\Models\Transaction;
use App\Models\TransactionSplit;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Says whether a legacy split conversion left every figure as it was.
 *
 * Two snapshots ({@see LegacySplitSnapshot}) must match bucket for bucket. The
 * one difference accepted is FX rounding in the monthly cashflow: lines are
 * converted with the residue on the last one, parts each on their own, so a
 * month holding parts booked in another currency may move by at most one
 * minor unit per such part. On top of that, each converted original is
 * checked against its own lines.
 *
 * @phpstan-import-type Figures from LegacySplitSnapshot
 *
 * @phpstan-type Difference array{family: string, bucket: string, before: ?int, after: ?int}
 * @phpstan-type Comparison array{differences: list<Difference>, fx_rounding: list<Difference>}
 */
class LegacySplitVerifier
{
    /** The family whose figures are converted to the user's currency. */
    private const string CONVERTED_FAMILY = 'cashflow';

    /** The free text a part copies from its original and the user may edit later. */
    private const array COPIED_TEXT = ['description', 'description_iv', 'notes', 'notes_iv'];

    /**
     * @param  Figures  $before
     * @param  Figures  $after
     * @param  array<string, int>  $foreignCurrencyParts  by `user id|Y-m`, see {@see LegacySplitPlan::foreignCurrencyParts()}
     * @return Comparison
     */
    public function compare(array $before, array $after, array $foreignCurrencyParts): array
    {
        $comparison = ['differences' => [], 'fx_rounding' => []];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $family) {
            $old = $before[$family] ?? [];
            $new = $after[$family] ?? [];

            foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $bucket) {
                $difference = ['family' => $family, 'bucket' => (string) $bucket, 'before' => $old[$bucket] ?? null, 'after' => $new[$bucket] ?? null];

                if ($difference['before'] !== $difference['after']) {
                    $comparison[$this->isFxRounding($difference, $foreignCurrencyParts) ? 'fx_rounding' : 'differences'][] = $difference;
                }
            }
        }

        return $comparison;
    }

    /**
     * Why each converted original does not match its lines, by original id:
     * one live part per line with the line's amount and category, adding up
     * to the original, each on the original's date and account, with its
     * description and notes, and carrying every label the original carries.
     * Parts keep no position, so they are matched to the lines as a set.
     *
     * @param  EloquentCollection<int, Transaction>  $originals
     * @return array<string, string>
     */
    public function partsAgainstLines(EloquentCollection $originals): array
    {
        $failures = [];

        foreach ($originals->chunk(200) as $chunk) {
            $parts = Transaction::query()->whereIn('split_parent_id', $chunk->modelKeys())->get()->groupBy('split_parent_id');
            $labels = $this->labelIds([...$chunk->modelKeys(), ...$parts->flatten(1)->pluck('id')->all()]);

            foreach ($chunk as $original) {
                $failure = $this->partsFailure($original, $parts->get($original->id, collect()), $labels);

                if ($failure !== null) {
                    $failures[$original->id] = $failure;
                }
            }
        }

        return $failures;
    }

    /**
     * Why each original an undo put back is not as it was before its
     * conversion: live again, with its lines and no part left.
     *
     * @param  EloquentCollection<int, Transaction>  $originals
     * @return array<string, string>
     */
    public function restored(EloquentCollection $originals): array
    {
        $live = Transaction::query()->whereKey($originals->modelKeys())->whereHas('splits')->pluck('id')->all();
        $withParts = Transaction::withTrashed()->whereIn('split_parent_id', $originals->modelKeys())->distinct()->pluck('split_parent_id')->all();

        return $originals
            ->mapWithKeys(fn (Transaction $original): array => [$original->id => match (true) {
                ! in_array($original->id, $live, true) => 'not live again with its lines',
                in_array($original->id, $withParts, true) => 'parts left behind',
                default => null,
            }])
            ->filter()
            ->all();
    }

    /**
     * @param  Difference  $difference
     * @param  array<string, int>  $foreignCurrencyParts
     */
    private function isFxRounding(array $difference, array $foreignCurrencyParts): bool
    {
        if ($difference['family'] !== self::CONVERTED_FAMILY || $difference['before'] === null || $difference['after'] === null) {
            return false;
        }

        [$userId, $month] = explode('|', $difference['bucket']);
        $allowed = $foreignCurrencyParts["{$userId}|{$month}"] ?? 0;

        return abs($difference['after'] - $difference['before']) <= $allowed;
    }

    /**
     * @param  Collection<int, Transaction>  $parts
     * @param  array<string, list<string>>  $labels  label ids by transaction id
     */
    private function partsFailure(Transaction $original, Collection $parts, array $labels): ?string
    {
        $lines = $original->splits->map(fn (TransactionSplit $line): string => $line->amount.'|'.$line->category_id)->sort()->values()->all();
        $made = $parts->map(fn (Transaction $part): string => $part->amount.'|'.$part->category_id)->sort()->values()->all();
        $originalLabels = $labels[$original->id] ?? [];

        return match (true) {
            $made !== $lines => 'parts do not match the lines',
            (int) $parts->sum('amount') !== $original->amount => 'parts do not add up to the original',
            $parts->contains(fn (Transaction $part): bool => ! $part->transaction_date->equalTo($original->transaction_date) || $part->account_id !== $original->account_id) => 'a part is not on the original\'s date and account',
            $parts->contains(fn (Transaction $part): bool => ($labels[$part->id] ?? []) !== $originalLabels) => 'a part does not carry the original\'s labels',
            $parts->contains(fn (Transaction $part): bool => $part->only(self::COPIED_TEXT) !== $original->only(self::COPIED_TEXT)) => 'a part does not carry the original\'s description and notes',
            default => null,
        };
    }

    /**
     * @param  list<string>  $transactionIds
     * @return array<string, list<string>> sorted label ids by transaction id
     */
    private function labelIds(array $transactionIds): array
    {
        return DB::table('label_transaction')
            ->whereIn('transaction_id', $transactionIds)
            ->orderBy('label_id')
            ->get(['transaction_id', 'label_id'])
            ->groupBy('transaction_id')
            ->map(fn (Collection $rows): array => $rows->pluck('label_id')->all())
            ->all();
    }
}
