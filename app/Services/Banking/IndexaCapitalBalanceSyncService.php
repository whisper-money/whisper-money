<?php

namespace App\Services\Banking;

use App\Models\Account;
use Illuminate\Support\Facades\Log;

class IndexaCapitalBalanceSyncService
{
    /**
     * Sync portfolio balances for an Indexa Capital account.
     * On first sync, stores all available daily historical balances.
     * On subsequent syncs, only processes entries since the last recorded balance.
     */
    public function sync(Account $account, IndexaCapitalClient $client, bool $isFirstSync = true): void
    {
        if (! $account->external_account_id) {
            return;
        }

        $performance = $client->getPerformance($account->external_account_id);
        $portfolios = $performance['portfolios'] ?? [];
        $netAmounts = $performance['net_amounts'] ?? [];

        if (empty($portfolios)) {
            Log::warning('No portfolio data from Indexa Capital', [
                'account_id' => $account->id,
                'external_account_id' => $account->external_account_id,
            ]);

            return;
        }

        $sinceDate = null;

        if (! $isFirstSync) {
            $lastBalanceDate = $account->balances()->max('balance_date');

            if ($lastBalanceDate) {
                $sinceDate = $lastBalanceDate;
            }
        }

        $count = 0;

        foreach ($portfolios as $entry) {
            $date = $entry['date'] ?? null;
            $value = $entry['total_amount'] ?? null;

            if ($date === null || $value === null) {
                continue;
            }

            if ($sinceDate !== null && $date < $sinceDate) {
                continue;
            }

            $balanceCents = (int) round(floatval($value) * 100);
            $investedAmountCents = $this->calculateInvestedAmount($entry, $netAmounts);

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
            ...($sinceDate ? ['since_date' => $sinceDate] : []),
        ]);
    }

    /**
     * Calculate invested amount from the net_amounts data.
     *
     * Uses net_amounts (cumulative net inflows keyed by YYYYMMDD) which represents
     * the actual money invested (inflows - outflows - tax_outflows), matching
     * what Indexa Capital shows as "investment" on their dashboard.
     *
     * Falls back to total_amount - return if net_amounts is unavailable.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, float>  $netAmounts
     */
    private function calculateInvestedAmount(array $entry, array $netAmounts): ?int
    {
        $date = $entry['date'] ?? null;

        if ($date !== null && ! empty($netAmounts)) {
            $dateKey = str_replace('-', '', $date);

            if (isset($netAmounts[$dateKey])) {
                return (int) round(floatval($netAmounts[$dateKey]) * 100);
            }
        }

        $totalAmount = $entry['total_amount'] ?? null;
        $returnValue = $entry['return'] ?? null;

        if ($totalAmount !== null && $returnValue !== null) {
            return (int) round((floatval($totalAmount) - floatval($returnValue)) * 100);
        }

        return null;
    }
}
