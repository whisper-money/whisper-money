<?php

namespace App\Services;

use App\Models\Account;
use App\Models\User;

class AccountUserCurrencyService
{
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
