<?php

namespace App\Services\Forecast;

use App\Enums\ForecastConfidence;
use App\Enums\ForecastFrequency;
use App\Models\Transaction;
use App\Services\Forecast\Data\ForecastCandidate;
use App\Services\Forecast\Data\TransferLeg;

/**
 * Finds the charges and incomes that repeat. Transactions are grouped by
 * account, direction and description fingerprint; those without a
 * fingerprint are grouped by amount (±10 %) and day of the month (±3)
 * instead. A group is a candidate when it has at least three charges, at least
 * three quarters of the gaps between them fit one period (one, two, three or
 * twelve months, by days so a charge a few days early still fits), and it is
 * still going: its next charge is not more than a week overdue.
 *
 * It is `high` only when every gap fits, it has four charges or more, the
 * amount moved less than 10 % and the fingerprint names it; `review`
 * otherwise. The incoming leg of a transfer between two of the user's
 * accounts is left out: the outgoing leg already carries it. Pure over the
 * transactions it is given; nothing is stored.
 */
class RecurringDetector
{
    private const MIN_CHARGES = 3;

    private const MIN_HIGH_CONFIDENCE_CHARGES = 4;

    private const FITTING_SHARE = 0.75;

    private const AMOUNT_BAND = 0.10;

    private const DAY_BAND = 3;

    /**
     * Days between two charges that fit each period, keyed by its months.
     */
    private const GAP_DAYS = [
        1 => [26, 35],
        2 => [56, 66],
        3 => [84, 98],
        12 => [350, 380],
    ];

    private const FREQUENCIES = [
        1 => ForecastFrequency::Monthly,
        2 => ForecastFrequency::Bimonthly,
        3 => ForecastFrequency::Quarterly,
        12 => ForecastFrequency::Yearly,
    ];

    public function __construct(private readonly CandidateBuilder $builder) {}

    /**
     * @param  list<array{0: string, 1: list<Transaction>}>  $groups  The history by fingerprint (see DescriptionFingerprint::groups()).
     * @param  array<string, TransferLeg>  $legs  The transfers in it (see TransferPairer).
     * @return list<ForecastCandidate>
     */
    public function detect(array $groups, array $legs, string $today): array
    {
        $withoutFingerprint = array_merge([], ...array_column(array_filter($groups, fn (array $group): bool => $group[0] === ''), 1));
        $grouped = [
            ...array_filter($groups, fn (array $group): bool => $group[0] !== ''),
            ...array_map(fn (array $charges): array => [null, $charges], $this->amountAndDayGroups($withoutFingerprint)),
        ];
        $candidates = [];

        foreach ($grouped as [$fingerprint, $charges]) {
            $candidate = $this->candidateFor($charges, $fingerprint, $legs, $today);

            if ($candidate !== null && ! $this->isIncomingTransfer($charges, $legs)) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * Clusters each account and direction's charges around the first charge
     * of each cluster, by amount and by day of the month.
     *
     * @param  list<Transaction>  $transactions  Oldest first.
     * @return list<list<Transaction>> Each oldest first.
     */
    private function amountAndDayGroups(array $transactions): array
    {
        $clusters = [];

        foreach ($transactions as $transaction) {
            $key = $this->directionKey($transaction);
            $index = $this->matchingCluster($clusters[$key] ?? [], $transaction);

            if ($index === null) {
                $clusters[$key][] = [$transaction];
            } else {
                $clusters[$key][$index][] = $transaction;
            }
        }

        return array_merge(...array_values($clusters ?: [[]]));
    }

    /**
     * @param  list<list<Transaction>>  $clusters
     */
    private function matchingCluster(array $clusters, Transaction $transaction): ?int
    {
        foreach ($clusters as $index => $cluster) {
            $reference = $cluster[0];
            $amountGap = abs(abs($transaction->amount) - abs($reference->amount));
            $dayGap = abs($transaction->transaction_date->day - $reference->transaction_date->day);

            if ($amountGap <= abs($reference->amount) * self::AMOUNT_BAND && min($dayGap, 31 - $dayGap) <= self::DAY_BAND) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<Transaction>  $charges  Oldest first.
     * @param  array<string, TransferLeg>  $legs
     */
    private function candidateFor(array $charges, ?string $fingerprint, array $legs, string $today): ?ForecastCandidate
    {
        if (count($charges) < self::MIN_CHARGES) {
            return null;
        }

        $schedule = $this->schedule($charges);

        if ($schedule === null || $this->builder->hasStopped($charges[array_key_last($charges)]->transaction_date->toDateString(), $schedule['months'], $today)) {
            return null;
        }

        $confidence = $fingerprint !== null && $schedule['everyGapFits'] && count($charges) >= self::MIN_HIGH_CONFIDENCE_CHARGES && $this->amountIsSteady($charges)
            ? ForecastConfidence::High
            : ForecastConfidence::Review;

        return $this->builder->build($charges, self::FREQUENCIES[$schedule['months']], $confidence, $fingerprint, $legs);
    }

    /**
     * The period most gaps between the charges fit, when enough of them do.
     *
     * @param  list<Transaction>  $charges  Oldest first.
     * @return array{months: int, everyGapFits: bool}|null
     */
    private function schedule(array $charges): ?array
    {
        $gaps = [];
        for ($index = 1; $index < count($charges); $index++) {
            $gaps[] = (int) $charges[$index - 1]->transaction_date->diffInDays($charges[$index]->transaction_date, true);
        }

        $best = null;
        $bestFits = 0;
        foreach (self::GAP_DAYS as $months => [$min, $max]) {
            $fits = count(array_filter($gaps, fn (int $gap): bool => $gap >= $min && $gap <= $max));

            if ($fits > $bestFits) {
                [$best, $bestFits] = [$months, $fits];
            }
        }

        if ($best === null || $bestFits < count($gaps) * self::FITTING_SHARE) {
            return null;
        }

        return ['months' => $best, 'everyGapFits' => $bestFits === count($gaps)];
    }

    /**
     * @param  list<Transaction>  $charges
     */
    private function amountIsSteady(array $charges): bool
    {
        $amounts = array_map(fn (Transaction $charge): int => abs($charge->amount), $charges);

        return max($amounts) - min($amounts) <= max($amounts) * self::AMOUNT_BAND;
    }

    /**
     * Whether most charges are the incoming side of a transfer whose outgoing
     * side is in another of the user's accounts.
     *
     * @param  list<Transaction>  $charges
     * @param  array<string, TransferLeg>  $legs
     */
    private function isIncomingTransfer(array $charges, array $legs): bool
    {
        $internal = array_filter($this->builder->legsOf($charges, $legs), fn (TransferLeg $leg): bool => $leg->isInternal());

        return $charges[0]->amount > 0 && count($internal) * 2 > count($charges);
    }

    private function directionKey(Transaction $transaction): string
    {
        return $transaction->account_id.'|'.($transaction->amount < 0 ? 'out' : 'in');
    }
}
