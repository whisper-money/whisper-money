<?php

namespace App\Services;

use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * What every transaction a filter set matches adds up to: how many there are,
 * their total and average in the reader's currency, whether they are all
 * expenses, all income or both, and the same filters month by month over the
 * six months the filter ends in.
 *
 * Built from SQL aggregates rather than the rows, so it is cheap enough for
 * every load of the transactions page and for counting a filter set before
 * it is applied.
 */
class TransactionFilterSummary
{
    /**
     * How many months the trend covers, the filter's last month included.
     */
    public const TREND_MONTHS = 6;

    public function __construct(private TransactionDayTotals $dayTotals) {}

    /**
     * The total adds up each day's figure, each currency converted once at
     * that day's rate as the day headers do, so it agrees with them to the
     * cent. A day with no rate leaves the total (and the average) null rather
     * than a figure that skipped it.
     *
     * @param  array<string, mixed>  $filters  As `IndexTransactionRequest::filters()` hands them, `user_id` included
     * @return array{count: int, total: ?int, average: ?int, kind: 'expense'|'income'|'mixed', currency: string, trend: list<array{month: string, total: ?int}>}
     */
    public function summarize(array $filters, string $currency): array
    {
        $signs = $this->matching($filters)
            ->toBase()
            ->selectRaw('count(*) as transactions_count')
            ->selectRaw('sum(case when transactions.amount < 0 then 1 else 0 end) as expenses_count')
            ->selectRaw('sum(case when transactions.amount > 0 then 1 else 0 end) as incomes_count')
            ->first();

        $count = (int) $signs->transactions_count;
        $total = $count === 0 ? 0 : $this->sumOfDays($this->dayTotals->forEveryDay($this->matching($filters), $currency));

        return [
            'count' => $count,
            'total' => $total,
            'average' => $total === null || $count === 0 ? $total : (int) round($total / $count),
            'kind' => $this->kind((int) $signs->expenses_count, (int) $signs->incomes_count),
            'currency' => $currency,
            'trend' => $this->trend($filters, $currency),
        ];
    }

    /**
     * Month by month over the window that ends in the filter's last month (or
     * this month without one), its own date range set aside for that window's.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{month: string, total: ?int}>
     */
    private function trend(array $filters, string $currency): array
    {
        $end = isset($filters['date_to'])
            ? CarbonImmutable::parse($filters['date_to'])->endOfMonth()
            : CarbonImmutable::now()->endOfMonth();
        $start = $end->subMonthsNoOverflow(self::TREND_MONTHS - 1)->startOfMonth();

        $days = collect($this->dayTotals->forEveryDay($this->matching([
            ...$filters,
            'date_from' => $start->toDateString(),
            'date_to' => $end->toDateString(),
        ]), $currency));

        $trend = [];

        for ($month = $start; $month->lessThan($end); $month = $month->addMonthNoOverflow()) {
            $key = $month->format('Y-m');

            $trend[] = [
                'month' => $key,
                'total' => $this->sumOfDays($days->filter(fn (array $day, string $date): bool => str_starts_with($date, $key))->all()),
            ];
        }

        return $trend;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Transaction>
     */
    private function matching(array $filters): Builder
    {
        return Transaction::query()
            ->where('user_id', $filters['user_id'])
            ->applyFilters($filters);
    }

    /**
     * @param  array<string, array{count: int, total: ?int}>  $days
     */
    private function sumOfDays(array $days): ?int
    {
        $total = 0;

        foreach ($days as $day) {
            if ($day['total'] === null) {
                return null;
            }

            $total += $day['total'];
        }

        return $total;
    }

    /**
     * @return 'expense'|'income'|'mixed'
     */
    private function kind(int $expenses, int $incomes): string
    {
        if ($expenses > 0 && $incomes === 0) {
            return 'expense';
        }

        if ($incomes > 0 && $expenses === 0) {
            return 'income';
        }

        return 'mixed';
    }
}
