<?php

namespace App\Services\Forecast;

use App\Enums\CategoryType;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Forecast\Data\SpikeCandidate;
use Illuminate\Support\Collection;

/**
 * Finds this month's charges far above what their parent category usually
 * sees, to ask once whether they were a one-off. A charge is a spike when it
 * is above the 95th percentile of the category's charges in the six complete
 * months before, and above three times the category's median monthly spend
 * over them (a month without spend counts as zero, from the category's first
 * charge on, so a young history is not read as spending nothing). Amounts
 * are the owner's share of each charge. A category needs a few
 * charges of history to judge against. What already paid a forecast line or
 * was marked a one-off is left out on both sides. Nothing is stored.
 *
 * The matching and spike flows of later phases read it; until then only the
 * tests do.
 *
 * @api
 */
class SpikeDetector
{
    private const BASELINE_MONTHS = 6;

    private const MIN_BASELINE_CHARGES = 3;

    private const PERCENTILE = 0.95;

    private const MEDIAN_MULTIPLE = 3;

    public function __construct(private readonly ForecastHistory $history) {}

    /**
     * @return list<SpikeCandidate>
     */
    public function detect(User $user, string $spaceId, ?string $today = null): array
    {
        $today ??= $user->localToday()->toDateString();
        $monthStart = ForecastCalendar::monthStart($today);
        $parents = $this->history->parentCategories($user);

        $charges = $this->history
            ->unplannedTransactions($user, $spaceId, ForecastCalendar::monthStart($today, -self::BASELINE_MONTHS), $today)
            ->filter(fn (Transaction $transaction): bool => $transaction->amount < 0
                && $transaction->category?->type === CategoryType::Expense
                && isset($parents[$transaction->category_id]))
            ->groupBy(fn (Transaction $transaction): string => $parents[$transaction->category_id].'|'.strtoupper($transaction->currency_code));

        $spikes = [];

        foreach ($charges as $key => $group) {
            [$baseline, $recent] = $group->partition(fn (Transaction $transaction): bool => $transaction->transaction_date->toDateString() < $monthStart);
            array_push($spikes, ...$this->spikesIn(explode('|', $key)[0], $baseline, $recent, $today));
        }

        return $spikes;
    }

    /**
     * @param  Collection<int, Transaction>  $baseline
     * @param  Collection<int, Transaction>  $recent
     * @return list<SpikeCandidate>
     */
    private function spikesIn(string $categoryId, Collection $baseline, Collection $recent, string $today): array
    {
        if ($baseline->count() < self::MIN_BASELINE_CHARGES) {
            return [];
        }

        $p95 = $this->percentile($baseline->map($this->spend(...))->all());
        $median = $this->monthlyMedian($baseline, $today);

        return $recent
            ->filter(fn (Transaction $transaction): bool => $this->spend($transaction) > $p95 && $this->spend($transaction) > $median * self::MEDIAN_MULTIPLE)
            ->map(fn (Transaction $transaction): SpikeCandidate => new SpikeCandidate(
                transactionId: $transaction->id,
                accountId: $transaction->account_id,
                categoryId: $categoryId,
                currencyCode: $transaction->currency_code,
                transactionDate: $transaction->transaction_date->toDateString(),
                amount: $this->spend($transaction),
                categoryP95: $p95,
                categoryMonthlyMedian: $median,
            ))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Transaction>  $baseline
     */
    private function monthlyMedian(Collection $baseline, string $today): int
    {
        $firstMonth = ForecastCalendar::monthKey($baseline->first()->transaction_date->toDateString());
        $totals = [];
        for ($offset = -self::BASELINE_MONTHS; $offset < 0; $offset++) {
            $month = ForecastCalendar::monthKey(ForecastCalendar::monthStart($today, $offset));

            if ($month >= $firstMonth) {
                $totals[$month] = 0;
            }
        }

        foreach ($baseline as $transaction) {
            $totals[ForecastCalendar::monthKey($transaction->transaction_date->toDateString())] += $this->spend($transaction);
        }

        return (int) round(collect($totals)->median());
    }

    /**
     * The owner's share of what the charge spent.
     */
    private function spend(Transaction $transaction): int
    {
        return -$transaction->account->shareOfAmount($transaction->amount);
    }

    /**
     * The 95th percentile, interpolated between the two nearest charges.
     *
     * @param  array<int, int>  $values
     */
    private function percentile(array $values): int
    {
        sort($values);
        $position = (count($values) - 1) * self::PERCENTILE;
        $lower = (int) floor($position);
        $upper = (int) ceil($position);

        return (int) round($values[$lower] + ($values[$upper] - $values[$lower]) * ($position - $lower));
    }
}
