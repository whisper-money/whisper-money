<?php

namespace App\Services\Banking;

use App\Models\Account;
use Illuminate\Support\Facades\Log;

class IndexaCapitalBalanceSyncService
{
    /**
     * Sync portfolio balances for an Indexa Capital account.
     * Stores up to one year of daily historical balances from the portfolios data.
     */
    public function sync(Account $account, IndexaCapitalClient $client): void
    {
        if (! $account->external_account_id) {
            return;
        }

        $performance = $client->getPerformance($account->external_account_id);
        $portfolios = $performance['portfolios'] ?? [];

        if (empty($portfolios)) {
            Log::warning('No portfolio data from Indexa Capital', [
                'account_id' => $account->id,
                'external_account_id' => $account->external_account_id,
            ]);

            return;
        }

        $count = 0;

        foreach ($portfolios as $entry) {
            $date = $entry['date'] ?? null;
            $value = $entry['total_amount'] ?? null;

            if ($date === null || $value === null) {
                continue;
            }

            $balanceCents = (int) round(floatval($value) * 100);
            $investedAmountCents = $this->calculateInvestedAmount($entry);

            $account->balances()->updateOrCreate(
                ['balance_date' => $date],
                [
                    'balance' => $balanceCents,
                    ...($investedAmountCents !== null ? ['invested_amount' => $investedAmountCents] : []),
                ],
            );

            $count++;
        }

        Log::info('Synced Indexa Capital balances', [
            'account_id' => $account->id,
            'days_synced' => $count,
        ]);
    }

    /**
     * Calculate invested amount from portfolio entry data.
     *
     * Uses instruments_cost + cash_amount when available (cost basis approach).
     * Falls back to total_amount - return if the return field is present.
     *
     * @param  array<string, mixed>  $entry
     */
    private function calculateInvestedAmount(array $entry): ?int
    {
        $instrumentsCost = $entry['instruments_cost'] ?? null;
        $cashAmount = $entry['cash_amount'] ?? null;

        if ($instrumentsCost !== null && $cashAmount !== null) {
            return (int) round((floatval($instrumentsCost) + floatval($cashAmount)) * 100);
        }

        $totalAmount = $entry['total_amount'] ?? null;
        $returnValue = $entry['return'] ?? null;

        if ($totalAmount !== null && $returnValue !== null) {
            return (int) round((floatval($totalAmount) - floatval($returnValue)) * 100);
        }

        return null;
    }
}
