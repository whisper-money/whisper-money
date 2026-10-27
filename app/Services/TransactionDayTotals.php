<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use stdClass;

/**
 * How many transactions each day holds and what they add up to, for the day
 * headers of the transactions list.
 *
 * Counted over every row the list's filters match, not over the page that was
 * served, so a day cut in two by the pagination shows its whole figure from
 * the first page on.
 */
class TransactionDayTotals
{
    public function __construct(private ExchangeRateService $exchangeRateService) {}

    /**
     * One grouped query over the given days. Each currency's sum is converted
     * at its own day's rate, the rate every row of that group would get, so it
     * matches converting row by row up to one rounding per currency instead of
     * one per row. A day holding an amount with no rate gets a null total: a
     * figure that skipped it would be wrong.
     *
     * A rate the table does not hold yet is fetched from the provider once and
     * stored, as every other conversion does; an unreachable provider answers
     * with no rate, so the day shows no total rather than holding up the page.
     *
     * @param  Builder<Transaction>  $filtered  The list's query with its filters, without order or eager loads
     * @param  Collection<int, string>  $dates  Days as `Y-m-d`
     * @return array<string, array{count: int, total: ?int}>
     */
    public function forDays(Builder $filtered, Collection $dates, string $currency): array
    {
        $dates = $dates->unique()->values();

        if ($dates->isEmpty()) {
            return [];
        }

        return $this->forEveryDay($filtered->whereIn('transactions.transaction_date', $dates->all()), $currency);
    }

    /**
     * The same figures for every day the query matches, as `forDays()` reads
     * them, for a summary that adds those days up rather than showing them.
     *
     * @param  Builder<Transaction>  $filtered  A query with its filters, without order or eager loads
     * @return array<string, array{count: int, total: ?int}>
     */
    public function forEveryDay(Builder $filtered, string $currency): array
    {
        $groups = $filtered
            ->toBase()
            ->select('transactions.transaction_date', 'transactions.currency_code')
            ->selectRaw('count(*) as transactions_count, sum(transactions.amount) as amount_sum')
            ->groupBy('transactions.transaction_date', 'transactions.currency_code')
            ->get();

        // Only days holding another currency need a rate, so a reader who
        // keeps everything in one currency never reads the rates table here.
        $this->exchangeRateService->preloadRates($currency, $groups
            ->reject(fn (stdClass $group): bool => strcasecmp($group->currency_code, $currency) === 0)
            ->map(fn (stdClass $group): string => $this->dayOf($group)));

        return $groups
            ->groupBy(fn (stdClass $group): string => $this->dayOf($group))
            ->map(fn (Collection $day, string $date): array => [
                'count' => (int) $day->sum('transactions_count'),
                'total' => $this->convertedTotal($day, $currency, $date),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, stdClass>  $day
     */
    private function convertedTotal(Collection $day, string $currency, string $date): ?int
    {
        $total = 0;

        foreach ($day as $group) {
            $converted = $this->exchangeRateService->convertOrNull($group->currency_code, $currency, (int) $group->amount_sum, $date);

            if ($converted === null) {
                return null;
            }

            $total += $converted;
        }

        return $total;
    }

    /**
     * The group's day as `Y-m-d`, whichever way the driver hands the DATE back.
     */
    private function dayOf(stdClass $group): string
    {
        return substr((string) $group->transaction_date, 0, 10);
    }
}
