<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\Concerns\ConvertsTransactionCurrency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Adds a window of transactions up in SQL for the cashflow figures, instead of
 * hydrating every row and summing in PHP.
 *
 * Rows are grouped by everything the cashflow rules read: account, category,
 * currency and, for uncategorized rows, the sign. The totals must still match
 * the row-by-row path to the cent, and two steps round per row: the currency
 * conversion and the owner's share of a partly owned account. A row that needs
 * either keeps its own date and amount in the group key, so identical rows
 * collapse into one group whose single conversion is multiplied by the count.
 * Every other row is in the user's currency at full ownership, where the sum
 * is exact.
 */
class CashflowAggregateQuery
{
    use ConvertsTransactionCurrency;

    public function __construct(private ExchangeRateService $exchangeRateService) {}

    /**
     * The groups of $transactions, ordered by where each group's first row sits
     * in (transaction_date, id) order, which is the order the row-by-row path
     * met them in. Callers that group further keep their first-seen order.
     *
     * @param  Builder<Transaction>  $transactions  the window, before
     *                                              {@see Transaction::scopeCountingTowardsTotals()}
     * @return Collection<int, CashflowAggregate>
     */
    public function aggregate(Builder $transactions, string $userCurrency): Collection
    {
        return $this->aggregates($transactions, $userCurrency);
    }

    /**
     * The same groups, also split by calendar month.
     *
     * @param  Builder<Transaction>  $transactions
     * @return Collection<string, Collection<int, CashflowAggregate>> keyed by `Y-m`
     */
    public function aggregateByMonth(Builder $transactions, string $userCurrency): Collection
    {
        return $this->aggregates($transactions, $userCurrency, "date_format(transactions.transaction_date, '%Y-%m')")
            ->groupBy(fn (CashflowAggregate $aggregate): string => $aggregate->bucket);
    }

    /**
     * The same groups, also split by named periods that do not overlap. A row
     * in none of them is left out.
     *
     * @param  Builder<Transaction>  $transactions
     * @param  array<string, PeriodComparator>  $periods
     * @return Collection<string, Collection<int, CashflowAggregate>> keyed by period name, every name present
     */
    public function aggregateByPeriod(Builder $transactions, string $userCurrency, array $periods): Collection
    {
        $cases = [];
        $bindings = [];

        foreach ($periods as $name => $period) {
            $cases[] = 'when transactions.transaction_date between ? and ? then ?';
            array_push($bindings, $period->from, $period->to, $name);
        }

        $grouped = $this->aggregates($transactions, $userCurrency, 'case '.implode(' ', $cases).' end', $bindings)
            ->groupBy(fn (CashflowAggregate $aggregate): string => (string) $aggregate->bucket);

        return collect(array_keys($periods))->mapWithKeys(fn (string $name): array => [$name => $grouped->get($name, collect())]);
    }

    /**
     * @param  Builder<Transaction>  $transactions
     * @param  array<int, mixed>  $bucketBindings
     * @return Collection<int, CashflowAggregate>
     */
    private function aggregates(Builder $transactions, string $userCurrency, ?string $bucket = null, array $bucketBindings = []): Collection
    {
        $groups = $this->groupedRows($transactions, $userCurrency, $bucket, $bucketBindings);

        $accounts = Account::query()->whereIn('id', $groups->pluck('account_id')->unique()->values())->get()->keyBy('id');
        $categories = Category::query()->whereIn('id', $groups->pluck('category_id')->filter()->unique()->values())->get()->keyBy('id');

        $this->exchangeRateService->preloadRates($userCurrency, $groups
            ->filter(fn (object $group): bool => $group->row_amount !== null
                && strcasecmp($this->sourceCurrency($group->currency_code, $accounts->get($group->account_id), $userCurrency), $userCurrency) !== 0)
            ->pluck('row_date')
            ->unique()
            ->values());

        // A model per group is the slow part of this path, so groups that read
        // the same to the cashflow rules share one stand-in.
        $standIns = [];

        return $groups->map(function (object $group) use ($accounts, $categories, $userCurrency, &$standIns): CashflowAggregate {
            $account = $accounts->get($group->account_id);

            return new CashflowAggregate(
                $standIns["{$group->account_id}|{$group->category_id}|{$group->amount_sign}"] ??= (new Transaction)
                    ->forceFill([
                        'account_id' => $group->account_id,
                        'category_id' => $group->category_id,
                        'amount' => (int) $group->amount_sign,
                    ])
                    ->setRelation('account', $account)
                    ->setRelation('category', $group->category_id === null ? null : $categories->get($group->category_id)),
                $group->row_amount === null
                    ? (int) $group->amount_sum
                    : $this->ownedAmount($account, $group, $userCurrency) * (int) $group->row_count,
                $group->bucket ?? null,
            );
        })->values();
    }

    /**
     * One row of a group that could not be summed in SQL, the way
     * {@see ConvertsTransactionCurrency::convertTransactionAmount()} reads it.
     */
    private function ownedAmount(?Account $account, object $group, string $userCurrency): int
    {
        $converted = $this->exchangeRateService->convert(
            $this->sourceCurrency($group->currency_code, $account, $userCurrency),
            $userCurrency,
            (int) $group->row_amount,
            $group->row_date,
        );

        return $account?->shareOfAmount($converted) ?? $converted;
    }

    /**
     * @param  Builder<Transaction>  $transactions
     * @param  array<int, mixed>  $bucketBindings
     * @return Collection<int, object>
     */
    private function groupedRows(Builder $transactions, string $userCurrency, ?string $bucket, array $bucketBindings): Collection
    {
        // Mirrors convertTransactionAmount(): a row is exact as a sum only when
        // it is already in the user's currency and its account is fully owned.
        // Compared as binary so MySQL's collation cannot call two codes equal
        // that PHP would not. A trashed account reads as missing in PHP, which
        // can only turn a row here into an exact one, never the other way round.
        $needsOwnRow = 'coalesce(accounts.ownership_percentage, 100) < 100'
            ." or cast(lower(coalesce(nullif(transactions.currency_code, ''), nullif(accounts.currency_code, ''), '')) as binary)"
            .' <> cast(lower(?) as binary)';

        return $transactions
            ->countingTowardsTotals()
            ->select(['transactions.account_id', 'transactions.category_id', 'transactions.currency_code'])
            ->when($bucket !== null, fn (Builder $query) => $query
                ->selectRaw("{$bucket} as bucket", $bucketBindings)
                ->groupBy('bucket'))
            ->selectRaw('case when transactions.category_id is null then sign(transactions.amount) else 0 end as amount_sign')
            ->selectRaw("case when {$needsOwnRow} then transactions.transaction_date end as row_date", [$userCurrency])
            ->selectRaw("case when {$needsOwnRow} then transactions.amount end as row_amount", [$userCurrency])
            ->selectRaw('sum(transactions.amount) as amount_sum')
            ->selectRaw('count(*) as row_count')
            ->groupBy('transactions.account_id', 'transactions.category_id', 'transactions.currency_code', 'amount_sign', 'row_date', 'row_amount')
            ->orderByRaw('min(concat(transactions.transaction_date, transactions.id))')
            ->toBase()
            ->get()
            ->values();
    }
}
