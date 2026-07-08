<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountBalance;
use Carbon\Carbon;
use Illuminate\Support\Str;

class LoanBalanceGeneratorService
{
    /**
     * Upsert historical balances in batches of this size. An old loan start
     * date (which has no lower bound in validation) can produce a very long
     * monthly series; building and upserting it all at once exhausts the queue
     * worker's memory in Arr::map/flatten (PHP-LARAVEL-49). Batching bounds
     * peak memory.
     */
    private const UPSERT_CHUNK_SIZE = 500;

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

            if (count($rows) >= self::UPSERT_CHUNK_SIZE) {
                $this->upsertBalances($rows);
                $rows = [];
            }
        }

        $this->upsertBalances($rows);
    }

    /**
     * Upsert a batch of balance rows, keyed by (account_id, balance_date).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsertBalances(array $rows): void
    {
        if ($rows === []) {
            return;
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
