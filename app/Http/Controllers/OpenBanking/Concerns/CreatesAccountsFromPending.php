<?php

namespace App\Http\Controllers\OpenBanking\Concerns;

use App\Enums\BankingConnectionStatus;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\AccountUserCurrencyService;

trait CreatesAccountsFromPending
{
    /**
     * Auto-create all pending accounts from a banking connection without user interaction.
     *
     * This resolves the correct account type based on the provider (Investment for
     * Indexa Capital, Binance, Bitpanda, and Coinbase; Checking for everything else) and clears the
     * pending data once accounts have been created.
     */
    private function createAccountsFromPending(User $user, BankingConnection $connection, AccountUserCurrencyService $accountUserCurrencyService): void
    {
        $bank = Bank::firstOrCreate(
            ['name' => $connection->aspsp_name, 'user_id' => null],
            ['name' => $connection->aspsp_name, 'logo' => $connection->aspsp_logo],
        );

        if (! $bank->logo && $connection->aspsp_logo) {
            $bank->update(['logo' => $connection->aspsp_logo]);
        }

        $accountType = $connection->provider->defaultAccountType();

        foreach ($connection->pending_accounts_data ?? [] as $accountData) {
            $uid = $accountData['uid'] ?? null;

            if (! $uid) {
                continue;
            }

            $currency = $accountData['currency'] ?? 'EUR';
            $name = $accountData['name']
                ?? $accountData['account_id']['iban']
                ?? $connection->aspsp_name.' Account';

            $account = $user->accounts()->create([
                'name' => $name,
                'name_iv' => null,
                'encrypted' => false,
                'bank_id' => $bank->id,
                'currency_code' => $currency,
                'type' => $accountType->value,
                'banking_connection_id' => $connection->id,
                'external_account_id' => $uid,
                'iban' => $accountData['account_id']['iban'] ?? null,
            ]);

            $accountUserCurrencyService->syncFromFirstAccount($account);
        }

        $connection->update([
            'status' => BankingConnectionStatus::Active,
            'pending_accounts_data' => null,
        ]);
    }
}
