<?php

namespace App\Services\Forecast;

use App\Enums\ForecastConfidence;
use App\Enums\ForecastFrequency;
use App\Models\Transaction;
use App\Services\Forecast\Data\ForecastCandidate;
use App\Services\Forecast\Data\TransferLeg;
use Carbon\Carbon;

/**
 * Suggests the yearly charges the history proves, and no others: in the data
 * a looser guess was right 1 % of the time. The last two charges of a
 * fingerprint on an account must be 12 months apart, give or take one, with
 * nothing of it in between, and within 15 % of each other; the next one must
 * not be more than a week overdue. Always `review`: the user confirms every
 * yearly line. Pure over the transactions it is given; nothing is stored.
 */
class AnnualSuggester
{
    private const MIN_GAP_DAYS = 335;

    private const MAX_GAP_DAYS = 396;

    private const AMOUNT_BAND = 0.15;

    private const OVERDUE_DAYS = 7;

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
            $lastTwo = array_slice($charges, -2);

            if ($fingerprint !== '' && count($lastTwo) === 2 && $this->isYearApart($lastTwo[0], $lastTwo[1], $today)) {
                $suggestions[] = $this->builder->build($lastTwo, ForecastFrequency::Yearly, ForecastConfidence::Review, $fingerprint, $legs);
            }
        }

        return $suggestions;
    }

    private function isYearApart(Transaction $earlier, Transaction $later, string $today): bool
    {
        $gap = (int) $earlier->transaction_date->diffInDays($later->transaction_date, true);
        $larger = max(abs($earlier->amount), abs($later->amount));
        $next = Carbon::parse(ForecastCalendar::addMonths($later->transaction_date->toDateString(), 12))->addDays(self::OVERDUE_DAYS);

        return $gap >= self::MIN_GAP_DAYS
            && $gap <= self::MAX_GAP_DAYS
            && abs(abs($earlier->amount) - abs($later->amount)) <= $larger * self::AMOUNT_BAND
            && $next->toDateString() >= $today;
    }
}
