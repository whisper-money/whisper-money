<?php

namespace App\Services\Transactions\LegacySplits;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * What one run of the legacy split conversion found: the originals it will
 * convert (or put back, for an undo) and the rows it leaves alone, with why.
 */
class LegacySplitPlan
{
    /**
     * @param  EloquentCollection<int, Transaction>  $originals  with `splits`, `account` and `user` loaded
     * @param  array<string, list<string>>  $skipped  transaction ids by reason
     * @param  int  $alreadyConverted  originals a previous run converted
     */
    public function __construct(
        public readonly EloquentCollection $originals,
        public readonly array $skipped = [],
        public readonly int $alreadyConverted = 0,
    ) {}

    /**
     * The months each user's originals fall in, `Y-m`, which the monthly
     * cashflow check reads.
     *
     * @return array<string, list<string>> by user id
     */
    public function monthsByUser(): array
    {
        return $this->originals
            ->groupBy('user_id')
            ->map(fn (Collection $originals): array => $originals
                ->map(fn (Transaction $original): string => $original->transaction_date->format('Y-m'))
                ->unique()
                ->sort()
                ->values()
                ->all())
            ->all();
    }

    /**
     * How many parts in each user's month are booked in a currency other
     * than the user's: lines convert with the residue on the last one, a part
     * converts on its own, so each such part may move a converted figure by
     * one minor unit, and nothing else may.
     *
     * @return array<string, int> keyed by `user id|Y-m`
     */
    public function foreignCurrencyParts(): array
    {
        $counts = [];

        foreach ($this->originals as $original) {
            $userCurrency = (string) $original->user?->currency_code;
            $currency = $original->currency_code ?: $original->account?->currency_code ?: $userCurrency;

            if (strcasecmp($currency, $userCurrency) === 0) {
                continue;
            }

            $bucket = $original->user_id.'|'.$original->transaction_date->format('Y-m');
            $counts[$bucket] = ($counts[$bucket] ?? 0) + $original->splits->count();
        }

        return $counts;
    }
}
