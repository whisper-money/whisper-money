<?php

namespace App\Services;

use App\Models\Account;
use Carbon\Carbon;

class RealEstateBalanceGeneratorService
{
    /**
     * Generate historical monthly balances from purchase date to today
     * using linear interpolation between purchase price and current value.
     *
     * Balances are placed on:
     * - The purchase date (with purchase price)
     * - The 1st of each month from the month after purchase to the current month
     * - Today (with current value)
     *
     * Use $from/$to to generate only a specific date range while still
     * interpolating against the full purchase-to-today timeline.
     */
    public function generateHistoricalBalances(
        Account $account,
        int $purchasePrice,
        Carbon $purchaseDate,
        int $currentValue,
        ?Carbon $from = null,
        ?Carbon $to = null,
    ): void {
        $today = Carbon::today();

        if ($purchaseDate->isAfter($today)) {
            return;
        }

        $totalDays = (int) $purchaseDate->diffInDays($today);

        // If purchase date is today, just ensure today's balance exists
        if ($totalDays === 0) {
            $account->balances()->updateOrCreate(
                ['balance_date' => $today->toDateString()],
                ['balance' => $currentValue],
            );

            return;
        }

        $rangeStart = $from ?? $purchaseDate;
        $rangeEnd = $to ?? $today;

        $dates = $this->buildDateList($purchaseDate, $today, $rangeStart, $rangeEnd);

        foreach ($dates as $date) {
            $elapsedDays = (int) $purchaseDate->diffInDays($date);
            $balance = (int) round(
                $purchasePrice + ($currentValue - $purchasePrice) * ($elapsedDays / $totalDays)
            );

            $account->balances()->updateOrCreate(
                ['balance_date' => $date->toDateString()],
                ['balance' => $balance],
            );
        }
    }

    /**
     * Build the list of dates for balance generation:
     * purchase date, 1st of each intermediate month, and today.
     *
     * Only dates within $rangeStart..$rangeEnd are included.
     *
     * @return Carbon[]
     */
    private function buildDateList(Carbon $purchaseDate, Carbon $today, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $dates = [];

        // Start with the purchase date if it falls within the range
        if ($purchaseDate->gte($rangeStart) && $purchaseDate->lte($rangeEnd)) {
            $dates[] = $purchaseDate->copy();
        }

        // Add the 1st of each month from the month after purchase to the current month
        $firstOfNextMonth = $purchaseDate->copy()->addMonth()->startOfMonth();

        while ($firstOfNextMonth->lte($today)) {
            if ($firstOfNextMonth->gte($rangeStart) && $firstOfNextMonth->lte($rangeEnd)) {
                // Avoid duplicate if today is the 1st and matches this date
                if (! $firstOfNextMonth->isSameDay($today)) {
                    $dates[] = $firstOfNextMonth->copy();
                }
            }

            $firstOfNextMonth->addMonth();
        }

        // End with today if it falls within the range (unless purchase date is today, handled above)
        if (! $purchaseDate->isSameDay($today) && $today->gte($rangeStart) && $today->lte($rangeEnd)) {
            $dates[] = $today->copy();
        }

        return $dates;
    }
}
