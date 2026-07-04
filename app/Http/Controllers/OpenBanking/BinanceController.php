<?php

namespace App\Http\Controllers\OpenBanking;

use App\Enums\BankingProvider;
use App\Http\Requests\OpenBanking\ConnectBinanceRequest;
use App\Services\AccountUserCurrencyService;
use App\Services\Banking\BinanceClient;
use Illuminate\Http\JsonResponse;

class BinanceController extends CryptoPortfolioConnectController
{
    /**
     * Validate Binance API credentials and create a connection.
     */
    public function store(ConnectBinanceRequest $request, AccountUserCurrencyService $accountUserCurrencyService): JsonResponse
    {
        return $this->connect($request->validated(), $accountUserCurrencyService);
    }

    protected function provider(): BankingProvider
    {
        return BankingProvider::Binance;
    }

    protected function providerName(): string
    {
        return 'Binance';
    }

    protected function bankLogo(): ?string
    {
        return 'https://whisper.money/storage/banks/logos/t1h5rqi19dJTPl6ZadziPjNwm0lrcdTFBRzB3iCy.png';
    }

    protected function fetchProviderData(array $validated): mixed
    {
        $client = new BinanceClient($validated['api_key'], $validated['api_secret']);
        $client->getAccount();

        return null;
    }

    protected function credentialErrorMessage(\Throwable $e): string
    {
        return 'Invalid API credentials or failed to connect to Binance.';
    }
}
