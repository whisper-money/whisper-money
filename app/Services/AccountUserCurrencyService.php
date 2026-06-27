<?php

namespace App\Services;

use App\Models\Account;
use App\Models\User;

class AccountUserCurrencyService
{
    /**
     * Resolve the currency code to store for a bank-imported account.
     *
     * Providers may report "XXX" (ISO 4217 "no currency") or omit the field;
     * in those cases fall back to the user's base currency, then to the app
     * default, so amounts stay convertible.
     */
    public function resolveImportedCurrency(?string $reported, User $user): string
    {
        foreach ([$reported, $user->currency_code] as $candidate) {
            $candidate = strtoupper(trim((string) $candidate));

            if ($candidate !== '' && $candidate !== 'XXX') {
                return $candidate;
            }
        }

        return strtoupper(config('cashier.currency', 'eur'));
    }

    public function syncFromFirstAccount(Account $account): void
    {
        $user = $account->user;

        if (! $user instanceof User) {
            return;
        }

        if ($user->accounts()->count() !== 1) {
            return;
        }

        $this->sync($user, $account);
    }

    private function sync(User $user, Account $account): void
    {
        $currencyCode = strtoupper($account->currency_code);

        if ($user->currency_code === $currencyCode) {
            return;
        }

        $user->forceFill(['currency_code' => $currencyCode])->save();
    }
}
