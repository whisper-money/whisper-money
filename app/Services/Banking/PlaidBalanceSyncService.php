<?php

namespace App\Services\Banking;

use App\Models\Account;
use Illuminate\Support\Facades\Log;

class PlaidBalanceSyncService
{
    /**
     * Sync today's balance for a Plaid account via /accounts/balance/get.
     *
     * The account's `external_account_id` is the Plaid `account_id`.
     */
    public function sync(Account $account, PlaidClient $client): void
    {
        if (! $account->external_account_id) {
            return;
        }

        $result = $client->getBalances();

        $plaidAccount = collect($result['accounts'] ?? [])
            ->firstWhere('account_id', $account->external_account_id);

        if ($plaidAccount === null) {
            return;
        }

        $currentBalance = $plaidAccount['balances']['current'] ?? null;

        if ($currentBalance === null) {
            return;
        }

        $amountCents = (int) round((float) $currentBalance * 100);

        $account->balances()->updateOrCreate(
            ['balance_date' => now()->toDateString()],
            ['balance' => $amountCents],
        );

        Log::info('Synced Plaid balance', [
            'account_id' => $account->id,
            'external_account_id' => $account->external_account_id,
            'balance' => $amountCents,
        ]);
    }
}
