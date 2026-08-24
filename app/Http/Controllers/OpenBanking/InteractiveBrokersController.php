<?php

namespace App\Http\Controllers\OpenBanking;

use App\Enums\BankingProvider;
use App\Exceptions\Banking\TransientBankingProviderException;
use App\Http\Requests\OpenBanking\ConnectInteractiveBrokersRequest;
use App\Models\User;
use App\Services\AccountUserCurrencyService;
use App\Services\Banking\InteractiveBrokersClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;

class InteractiveBrokersController extends OpenBankingConnectController
{
    /**
     * Validate the Flex credentials and create a connection.
     */
    public function store(ConnectInteractiveBrokersRequest $request, AccountUserCurrencyService $accountUserCurrencyService): JsonResponse
    {
        return $this->connect($request->validated(), $accountUserCurrencyService);
    }

    protected function provider(): BankingProvider
    {
        return BankingProvider::InteractiveBrokers;
    }

    protected function providerName(): string
    {
        return 'Interactive Brokers';
    }

    protected function bankLogo(): ?string
    {
        return '/images/banks/logos/interactive-brokers.png';
    }

    protected function aspspCountry(array $validated): string
    {
        return 'US';
    }

    protected function fetchProviderData(array $validated): mixed
    {
        $client = new InteractiveBrokersClient($validated['token'], $validated['query_id']);

        return $client->fetchStatement();
    }

    /**
     * Turn a Flex failure into a message the user can act on: bad credentials,
     * a busy/rate-limited service, or a statement that is still generating.
     */
    protected function credentialErrorMessage(\Throwable $e): string
    {
        if ($e instanceof RequestException && in_array($e->response->status(), [401, 403], true)) {
            return 'Invalid Flex token or query ID, or failed to connect to Interactive Brokers.';
        }

        if ($e instanceof RequestException && $e->response->status() === 429) {
            return 'Interactive Brokers is rate limiting requests. Please wait a few minutes and try again.';
        }

        if ($e instanceof TransientBankingProviderException) {
            return 'Interactive Brokers is still preparing your statement. Please try again in a moment.';
        }

        return 'Invalid Flex token or query ID, or failed to connect to Interactive Brokers.';
    }

    protected function emptyProviderDataMessage(mixed $providerData): ?string
    {
        if (empty($providerData)) {
            return 'No accounts found in the Flex statement. Check that your Flex Query includes the NAV section.';
        }

        return null;
    }

    /**
     * Build pending accounts from the parsed Flex statement.
     *
     * @param  array<string, array{account_id: string, currency: string, navByDate: array<string, float>, investedAmount: float|null}>  $providerData
     */
    protected function buildPendingAccounts(mixed $providerData, User $user): array
    {
        $pending = [];

        foreach ($providerData as $account) {
            $pending[] = [
                'uid' => $account['account_id'],
                'currency' => $account['currency'] !== '' ? $account['currency'] : 'USD',
                'name' => "Interactive Brokers ({$account['account_id']})",
            ];
        }

        return $pending;
    }
}
