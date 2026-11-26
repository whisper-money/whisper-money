<?php

namespace App\Services\Forecast;

use App\Enums\ForecastConfidence;
use App\Enums\ForecastFrequency;
use App\Models\Transaction;
use App\Services\Forecast\Data\ForecastCandidate;
use App\Services\Forecast\Data\TransferLeg;
use App\Support\Money;

/**
 * Suggests the yearly charges the history proves, and no others: in the data
 * a looser guess was right 1 % of the time. A fingerprint on an account must
 * have exactly two charges in the history, 12 months apart, give or take
 * one, within 15 % of each other and of at least 30 in the account's
 * currency; the next one must not be more than a week overdue. Always `review`: the user confirms every
 * yearly line. Pure over the transactions it is given; nothing is stored.
 */
class AnnualSuggester
{
    private const MIN_GAP_DAYS = 335;

    private const MAX_GAP_DAYS = 396;

    private const AMOUNT_BAND = 0.15;

    private const MIN_MAJOR_UNITS = 30;

    public function __construct(private readonly CandidateBuilder $builder) {}

    /**
     * @param  list<array{0: string, 1: list<Transaction>}>  $groups  The history by fingerprint, oldest first (see DescriptionFingerprint::groups()).
     * @param  array<string, TransferLeg>  $legs  The transfers in it (see TransferPairer).
     * @return list<ForecastCandidate>
     */
    public function suggest(array $groups, array $legs, string $today): array
    {
        $suggestions = [];

        foreach ($groups as [$fingerprint, $charges]) {
            if ($fingerprint !== '' && count($charges) === 2 && $this->isYearApart($charges[0], $charges[1], $today)) {
                $suggestions[] = $this->builder->build($charges, ForecastFrequency::Yearly, ForecastConfidence::Review, $fingerprint, $legs);
            }
        }

        return $suggestions;
    }

    private function isYearApart(Transaction $earlier, Transaction $later, string $today): bool
    {
        $gap = (int) $earlier->transaction_date->diffInDays($later->transaction_date, true);
        $larger = max(abs($earlier->amount), abs($later->amount));
        $smaller = min(abs($earlier->amount), abs($later->amount));

        return $gap >= self::MIN_GAP_DAYS
            && $gap <= self::MAX_GAP_DAYS
            && $larger - $smaller <= $larger * self::AMOUNT_BAND
            && $smaller >= Money::toMinor(self::MIN_MAJOR_UNITS, $later->account->currency_code ?? $later->currency_code)
            && ! $this->builder->hasStopped($later->transaction_date->toDateString(), 12, $today);
    }
}
