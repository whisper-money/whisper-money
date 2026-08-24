<?php

namespace App\Http\Controllers\OpenBanking;

use App\Enums\BankingProvider;
use App\Http\Requests\OpenBanking\ConnectIndexaCapitalRequest;
use App\Models\User;
use App\Services\AccountUserCurrencyService;
use App\Services\Banking\IndexaCapitalClient;
use Illuminate\Http\JsonResponse;

class IndexaCapitalController extends OpenBankingConnectController
{
    /**
     * Validate the Indexa Capital API token and create a connection.
     */
    public function store(ConnectIndexaCapitalRequest $request, AccountUserCurrencyService $accountUserCurrencyService): JsonResponse
    {
        return $this->connect($request->validated(), $accountUserCurrencyService);
    }

    protected function provider(): BankingProvider
    {
        return BankingProvider::IndexaCapital;
    }

    protected function providerName(): string
    {
        return 'Indexa Capital';
    }

    protected function bankLogo(): ?string
    {
        return '/images/banks/logos/indexa-capital.jpg';
    }

    protected function aspspCountry(array $validated): string
    {
        return 'ES';
    }

    protected function fetchProviderData(array $validated): mixed
    {
        $client = new IndexaCapitalClient($validated['api_token']);

        return $client->getUser();
    }

    protected function credentialErrorMessage(\Throwable $e): string
    {
        return 'Invalid API token or failed to connect to Indexa Capital.';
    }

    /**
     * Build the pending accounts data in the same format as EnableBanking.
     *
     * @param  array{accounts?: array<int, array{account_number?: string, type?: string}>}  $providerData
     */
    protected function buildPendingAccounts(mixed $providerData, User $user): array
    {
        $accounts = [];

        foreach ($providerData['accounts'] ?? [] as $account) {
            $accountNumber = $account['account_number'] ?? null;

            if (! $accountNumber) {
                continue;
            }

            $type = $account['type'] ?? 'mutual';
            $typeName = $type === 'pension' ? 'Pension Plan' : 'Investment Portfolio';

            $accounts[] = [
                'uid' => $accountNumber,
                'currency' => 'EUR',
                'name' => "{$typeName} ({$accountNumber})",
            ];
        }

        return $accounts;
    }
}
