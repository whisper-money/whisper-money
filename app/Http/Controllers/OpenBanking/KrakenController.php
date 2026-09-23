<?php

namespace App\Http\Controllers\OpenBanking;

use App\Enums\BankingProvider;
use App\Exceptions\Banking\MissingProviderPermissionException;
use App\Http\Requests\OpenBanking\ConnectKrakenRequest;
use App\Services\AccountUserCurrencyService;
use App\Services\Banking\KrakenClient;
use Illuminate\Http\JsonResponse;

class KrakenController extends CryptoPortfolioConnectController
{
    /**
     * Validate the Kraken API key and secret and create a connection.
     */
    public function store(ConnectKrakenRequest $request, AccountUserCurrencyService $accountUserCurrencyService): JsonResponse
    {
        return $this->connect($request->validated(), $accountUserCurrencyService);
    }

    protected function provider(): BankingProvider
    {
        return BankingProvider::Kraken;
    }

    protected function providerName(): string
    {
        return 'Kraken';
    }

    protected function bankLogo(): ?string
    {
        return '/images/banks/logos/kraken.png';
    }

    protected function fetchProviderData(array $validated): mixed
    {
        (new KrakenClient($validated['api_key'], $validated['api_secret']))->verifyPermissions();

        return null;
    }

    protected function credentialErrorMessage(\Throwable $e): string
    {
        return $e instanceof MissingProviderPermissionException
            ? $e->getMessage()
            : __('Invalid API key or failed to connect to Kraken.');
    }
}
