<?php

namespace App\Services\Forecast;

use App\Enums\ForecastConfidence;
use App\Enums\ForecastFrequency;
use App\Enums\ForecastSuggestionKind;
use App\Models\Transaction;
use App\Services\Forecast\Data\ForecastCandidate;
use App\Services\Forecast\Data\TransferLeg;
use Carbon\Carbon;

/**
 * Turns the charges a detector recognised as one line into its suggestion:
 * the amount is the mean of the last three, the tolerance how far the recent
 * ones strayed from it, the category the one they carry most often, and the
 * schedule continues from the occurrence the last charge paid. A charge can
 * land a few days early and pay next month's occurrence (a salary paid on 30
 * September for October), so the day of the month is read across the turn of
 * the month.
 */
class CandidateBuilder
{
    private const AMOUNT_CHARGES = 3;

    private const TOLERANCE_CHARGES = 6;

    /**
     * Days further apart than this are read across the turn of the month: the
     * 30th and the 1st are two days apart, not 29.
     */
    private const MONTH_TURN_SPREAD = 15;

    /**
     * @param  list<Transaction>  $charges  Oldest first.
     * @param  array<string, TransferLeg>  $legs
     */
    public function build(array $charges, ForecastFrequency $frequency, ForecastConfidence $confidence, ?string $fingerprint, array $legs): ForecastCandidate
    {
        $last = $charges[array_key_last($charges)];
        $amount = $this->meanAmount(array_slice($charges, -self::AMOUNT_CHARGES));
        $anchorDay = $this->anchorDay($charges);
        $transferLegs = array_values(array_filter(array_map(fn (Transaction $charge): ?TransferLeg => $legs[$charge->id] ?? null, $charges)));
        $isTransfer = count($transferLegs) * 2 > count($charges);
        $destinationAccountId = $isTransfer ? $this->mostFrequent(array_map(fn (TransferLeg $leg): ?string => $leg->counterpartAccountId, $transferLegs)) : null;

        return new ForecastCandidate(
            kind: $isTransfer ? ForecastSuggestionKind::Transfer : ($frequency === ForecastFrequency::Yearly ? ForecastSuggestionKind::Annual : ForecastSuggestionKind::Recurring),
            accountId: $last->account_id,
            currencyCode: $last->account->currency_code ?? $last->currency_code,
            name: $this->name($last),
            fingerprint: $fingerprint,
            categoryId: $this->mostFrequent(array_map(fn (Transaction $charge): ?string => $charge->category_id, $charges)),
            frequency: $frequency,
            amount: $amount,
            amountTolerance: $this->tolerance(array_slice($charges, -self::TOLERANCE_CHARGES), $amount),
            anchorDay: $anchorDay,
            nextDate: $this->nextDate($last->transaction_date->toDateString(), $anchorDay, (int) $frequency->months()),
            lastChargedOn: $last->transaction_date->toDateString(),
            confidence: $confidence,
            transactionIds: array_map(fn (Transaction $charge): string => $charge->id, $charges),
            destinationAccountId: $destinationAccountId,
            destinationUnknown: $isTransfer && $destinationAccountId === null,
        );
    }

    /**
     * @param  list<Transaction>  $charges
     */
    private function meanAmount(array $charges): int
    {
        return (int) round(array_sum(array_map(fn (Transaction $charge): int => $charge->amount, $charges)) / count($charges));
    }

    /**
     * @param  list<Transaction>  $charges
     */
    private function tolerance(array $charges, int $amount): int
    {
        return max(array_map(fn (Transaction $charge): int => abs($charge->amount - $amount), $charges));
    }

    /**
     * The median day of the month the charges land on, read across the turn
     * of the month when they straddle it.
     *
     * @param  list<Transaction>  $charges
     */
    public function anchorDay(array $charges): int
    {
        $days = array_map(fn (Transaction $charge): int => $charge->transaction_date->day, $charges);

        if (max($days) - min($days) > self::MONTH_TURN_SPREAD) {
            $days = array_map(fn (int $day): int => $day <= self::MONTH_TURN_SPREAD ? $day + 31 : $day, $days);
        }

        $median = (int) round(collect($days)->median());

        return $median > 31 ? $median - 31 : $median;
    }

    /**
     * The first occurrence after the one the last charge paid: of the dates
     * on the anchor day in the month before, of and after the charge, the one
     * nearest to it, moved on by one period.
     */
    private function nextDate(string $lastCharge, int $anchorDay, int $stepMonths): string
    {
        [$year, $month] = ForecastCalendar::parse($lastCharge);
        $chargeIndex = $year * 12 + $month - 1;
        $charged = Carbon::parse($lastCharge);
        $paidIndex = $chargeIndex;

        foreach ([$chargeIndex - 1, $chargeIndex + 1] as $index) {
            if ($charged->diffInDays(Carbon::parse($this->onDay($index, $anchorDay)), true) < $charged->diffInDays(Carbon::parse($this->onDay($paidIndex, $anchorDay)), true)) {
                $paidIndex = $index;
            }
        }

        return $this->onDay($paidIndex + $stepMonths, $anchorDay);
    }

    /**
     * The date on `$day` of the month `$monthIndex` (year × 12 + month − 1),
     * or on its last day when it is shorter.
     */
    private function onDay(int $monthIndex, int $day): string
    {
        $year = intdiv($monthIndex, 12);
        $month = $monthIndex % 12 + 1;

        return ForecastCalendar::format($year, $month, min($day, ForecastCalendar::daysInMonth($year, $month)));
    }

    private function name(Transaction $charge): string
    {
        $creditor = trim((string) $charge->creditor_name);

        return $creditor !== '' ? $creditor : trim((string) $charge->description);
    }

    /**
     * The most frequent non-null value; on a tie, the latest of them.
     *
     * @param  list<string|null>  $values  Oldest first.
     */
    private function mostFrequent(array $values): ?string
    {
        $counts = array_count_values(array_reverse(array_filter($values, fn (?string $value): bool => $value !== null)));

        if ($counts === []) {
            return null;
        }

        arsort($counts);

        return (string) array_key_first($counts);
    }
}
