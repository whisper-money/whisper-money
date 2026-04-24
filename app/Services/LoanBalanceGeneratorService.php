<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountBalance;
use Carbon\Carbon;
use Illuminate\Support\Str;

class LoanBalanceGeneratorService
{
    /**
     * Generate historical monthly balances from a loan's start date to today
     * using linear interpolation between the original amount owed and the
     * current balance owed.
     *
     * Balances are placed on:
     * - The loan start date (with the original amount)
     * - The 1st of each month from the month after start to the current month
     * - Today (with the current balance)
     *
     * Use $from/$to to generate only a specific date range while still
     * interpolating against the full start-to-today timeline.
     */
    public function generateHistoricalBalances(
        Account $account,
        int $originalAmount,
        Carbon $startDate,
        int $currentBalance,
        ?Carbon $from = null,
        ?Carbon $to = null,
    ): void {
        $today = Carbon::today();

        if ($startDate->isAfter($today)) {
            return;
        }

        $totalDays = (int) $startDate->diffInDays($today);

        if ($totalDays === 0) {
            $account->balances()->updateOrCreate(
                ['balance_date' => $today->toDateString()],
                ['balance' => $currentBalance],
            );

            return;
        }

        $rangeStart = $from ?? $startDate;
        $rangeEnd = $to ?? $today;

        $dates = $this->buildDateList($startDate, $today, $rangeStart, $rangeEnd);

        if (empty($dates)) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($dates as $date) {
            $elapsedDays = $startDate->diffInDays($date);
            $balance = (int) round(
                $originalAmount + ($currentBalance - $originalAmount) * ($elapsedDays / $totalDays)
            );

            $rows[] = [
                'id' => (string) Str::uuid(),
                'account_id' => $account->id,
                'balance_date' => $date->toDateString(),
                'balance' => $balance,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        AccountBalance::upsert($rows, ['account_id', 'balance_date'], ['balance', 'updated_at']);
    }

    /**
     * Build the list of dates for balance generation:
     * start date, 1st of each intermediate month, and today.
     *
     * Only dates within $rangeStart..$rangeEnd are included.
     *
     * @return Carbon[]
     */
    private function buildDateList(Carbon $startDate, Carbon $today, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $dates = [];

        if ($startDate->gte($rangeStart) && $startDate->lte($rangeEnd)) {
            $dates[] = $startDate->copy();
        }

        $firstOfNextMonth = $startDate->copy()->addMonth()->startOfMonth();

        while ($firstOfNextMonth->lte($today)) {
            if ($firstOfNextMonth->gte($rangeStart) && $firstOfNextMonth->lte($rangeEnd)) {
                if (! $firstOfNextMonth->isSameDay($today)) {
                    $dates[] = $firstOfNextMonth->copy();
                }
            }

            $firstOfNextMonth->addMonth();
        }

        if (! $startDate->isSameDay($today) && $today->gte($rangeStart) && $today->lte($rangeEnd)) {
            $dates[] = $today->copy();
        }

        return $dates;
    }
}
