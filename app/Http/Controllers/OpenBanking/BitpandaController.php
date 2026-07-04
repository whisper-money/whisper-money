<?php

namespace App\Http\Controllers\OpenBanking;

use App\Enums\BankingProvider;
use App\Http\Requests\OpenBanking\ConnectBitpandaRequest;
use App\Models\User;
use App\Services\AccountUserCurrencyService;
use App\Services\Banking\BitpandaClient;
use Illuminate\Http\JsonResponse;

class BitpandaController extends OpenBankingConnectController
{
    /**
     * Validate Bitpanda API key and create a connection.
     */
    public function store(ConnectBitpandaRequest $request, AccountUserCurrencyService $accountUserCurrencyService): JsonResponse
    {
        return $this->connect($request->validated(), $accountUserCurrencyService);
    }

    protected function provider(): BankingProvider
    {
        return BankingProvider::Bitpanda;
    }

    protected function providerName(): string
    {
        return 'Bitpanda';
    }

    protected function bankLogo(): ?string
    {
        return 'https://whisper.money/storage/banks/logos/7Y6gl0gaFH1mStJMcUQ9VpgzX1kduyumm0dDhGlf.png';
    }

    protected function aspspCountry(array $validated): string
    {
        return $validated['country'];
    }

    protected function fetchProviderData(array $validated): mixed
    {
        $client = new BitpandaClient($validated['api_key']);
        $client->getCryptoWallets();

        return null;
    }

    protected function validationFailureLogMessage(): string
    {
        return 'Bitpanda credential validation failed';
    }

    protected function credentialErrorMessage(\Throwable $e): string
    {
        return 'Invalid API key or failed to connect to Bitpanda.';
    }

    protected function buildPendingAccounts(mixed $providerData, User $user): array
    {
        return [
            [
                'uid' => 'bitpanda-portfolio',
                'currency' => $user->currency_code,
                'name' => 'Crypto Portfolio',
            ],
        ];
    }
}
