<?php

namespace App\Http\Controllers\OpenBanking;

use App\Enums\BankingProvider;
use App\Http\Requests\OpenBanking\ConnectCoinbaseRequest;
use App\Models\User;
use App\Services\AccountUserCurrencyService;
use App\Services\Banking\CoinbaseClient;
use Illuminate\Http\JsonResponse;

class CoinbaseController extends OpenBankingConnectController
{
    /**
     * Validate Coinbase CDP API credentials and create a connection.
     */
    public function store(ConnectCoinbaseRequest $request, AccountUserCurrencyService $accountUserCurrencyService): JsonResponse
    {
        return $this->connect($request->validated(), $accountUserCurrencyService);
    }

    protected function provider(): BankingProvider
    {
        return BankingProvider::Coinbase;
    }

    protected function providerName(): string
    {
        return 'Coinbase';
    }

    protected function bankLogo(): ?string
    {
        return 'https://whisper.money/storage/banks/logos/coinbase.png';
    }

    protected function aspspCountry(array $validated): string
    {
        return $validated['country'];
    }

    protected function fetchProviderData(array $validated): mixed
    {
        $client = new CoinbaseClient($validated['api_key_name'], $validated['private_key']);
        $client->getAccounts(limit: 1);

        return null;
    }

    protected function validationFailureLogMessage(): string
    {
        return 'Coinbase credential validation failed';
    }

    protected function credentialErrorMessage(\Throwable $e): string
    {
        return 'Invalid API credentials or failed to connect to Coinbase.';
    }

    protected function buildPendingAccounts(mixed $providerData, User $user): array
    {
        return [
            [
                'uid' => 'coinbase-portfolio',
                'currency' => $user->currency_code,
                'name' => 'Crypto Portfolio',
            ],
        ];
    }
}
