<?php

namespace App\Services\Banking;

use App\Models\Account;
use Illuminate\Support\Facades\Log;

class WiseBalanceSyncService
{
    /**
     * Sync today's balance for a Wise currency wallet via the borderless account API.
     *
     * The account's `external_account_id` must be in the format
     * "{profileId}:{currency}" (e.g. "36875276:EUR").
     */
    public function sync(Account $account, WiseClient $client): void
    {
        if (! $account->external_account_id) {
            return;
        }

        [$profileId, $currency] = explode(':', $account->external_account_id, 2);

        $borderless = $client->getBorderlessAccount((int) $profileId);

        $walletBalance = collect($borderless['balances'] ?? [])
            ->firstWhere('currency', $currency);

        $value = $walletBalance['amount']['value'] ?? null;

        if ($value === null) {
            return;
        }

        $amountCents = (int) round((float) $value * 100);

        $account->balances()->updateOrCreate(
            ['balance_date' => now()->toDateString()],
            ['balance' => $amountCents],
        );

        Log::info('Synced Wise balance', [
            'account_id' => $account->id,
            'currency' => $currency,
            'balance' => $amountCents,
        ]);
    }
}
