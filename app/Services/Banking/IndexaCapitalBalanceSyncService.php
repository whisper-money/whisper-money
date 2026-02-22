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
            $returnValue = $entry['return'] ?? null;
            $investedAmountCents = $returnValue !== null
                ? (int) round((floatval($value) - floatval($returnValue)) * 100)
                : null;

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
}
