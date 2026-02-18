<?php

namespace App\Services\Banking;

use App\Models\Account;
use Illuminate\Support\Facades\Log;

class IndexaCapitalBalanceSyncService
{
    /**
     * Sync the current portfolio balance for an Indexa Capital account.
     */
    public function sync(Account $account, IndexaCapitalClient $client): void
    {
        if (! $account->external_account_id) {
            return;
        }

        $performance = $client->getPerformance($account->external_account_id);

        $amount = $this->extractPortfolioValue($performance);

        if ($amount === null) {
            Log::warning('Could not extract portfolio value from Indexa Capital performance data', [
                'account_id' => $account->id,
                'external_account_id' => $account->external_account_id,
            ]);

            return;
        }

        $date = now()->toDateString();

        $account->balances()->updateOrCreate(
            ['balance_date' => $date],
            ['balance' => $amount],
        );

        Log::info('Synced Indexa Capital balance', [
            'account_id' => $account->id,
            'balance' => $amount,
            'date' => $date,
        ]);
    }

    /**
     * Extract the current portfolio value from performance data.
     * Returns amount in cents (bigint).
     */
    private function extractPortfolioValue(array $performance): ?int
    {
        $value = $performance['total_amount']
            ?? $performance['value']
            ?? $performance['portfolio_value']
            ?? $performance['amount']
            ?? null;

        if ($value === null) {
            return null;
        }

        return (int) round(floatval($value) * 100);
    }
}
