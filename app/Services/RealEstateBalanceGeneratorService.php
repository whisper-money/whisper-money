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
     */
    public function generateHistoricalBalances(
        Account $account,
        int $purchasePrice,
        Carbon $purchaseDate,
        int $currentValue,
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

        $dates = $this->buildDateList($purchaseDate, $today);

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
     * @return Carbon[]
     */
    private function buildDateList(Carbon $purchaseDate, Carbon $today): array
    {
        $dates = [];

        // Start with the purchase date
        $dates[] = $purchaseDate->copy();

        // Add the 1st of each month from the month after purchase to the current month
        $firstOfNextMonth = $purchaseDate->copy()->addMonth()->startOfMonth();

        while ($firstOfNextMonth->lte($today)) {
            // Avoid duplicate if today is the 1st and matches this date
            if (! $firstOfNextMonth->isSameDay($today)) {
                $dates[] = $firstOfNextMonth->copy();
            }

            $firstOfNextMonth->addMonth();
        }

        // End with today (unless purchase date is today, handled above)
        if (! $purchaseDate->isSameDay($today)) {
            $dates[] = $today->copy();
        }

        return $dates;
    }
}
