<?php

namespace App\Http\Controllers\OpenBanking;

use App\Models\User;

abstract class CryptoPortfolioConnectController extends OpenBankingConnectController
{
    protected function aspspCountry(array $validated): string
    {
        return $validated['country'];
    }

    /**
     * Crypto providers expose a single aggregated portfolio, mapped to one
     * pending account named after the provider (e.g. "binance-portfolio").
     */
    protected function buildPendingAccounts(mixed $providerData, User $user): array
    {
        return [
            [
                'uid' => $this->provider()->value.'-portfolio',
                'currency' => $user->currency_code,
                'name' => 'Crypto Portfolio',
            ],
        ];
    }
}
