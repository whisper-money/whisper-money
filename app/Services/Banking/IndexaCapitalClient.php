<?php

namespace App\Services\Banking;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IndexaCapitalClient
{
    private const BASE_URL = 'https://api.indexacapital.com';

    public function __construct(
        private string $apiToken,
    ) {}

    /**
     * Get the authenticated user's info, including accounts list.
     *
     * @return array{accounts: array<int, array{account_number: string, status: string, type: string}>, accounts_relations: array}
     */
    public function getUser(): array
    {
        $response = $this->client()->get('/users/me');

        $response->throw();

        return $response->json();
    }

    /**
     * Get all accounts for the authenticated user.
     *
     * @return array<int, array{account_number: string, status: string, type: string, currency: string}>
     */
    public function getAccounts(): array
    {
        $userData = $this->getUser();

        $accounts = [];

        foreach ($userData['accounts'] ?? [] as $account) {
            if (! isset($account['account_number'])) {
                continue;
            }

            $accountDetails = $this->getAccount($account['account_number']);
            $accounts[] = $accountDetails;
        }

        return $accounts;
    }

    /**
     * Get detailed account information.
     *
     * @return array{account_number: string, currency: string, status: string, type: string, profile: array}
     */
    public function getAccount(string $accountNumber): array
    {
        $response = $this->client()->get("/accounts/{$accountNumber}");

        $response->throw();

        return $response->json();
    }

    /**
     * Get performance data for an account, including current portfolio value.
     *
     * @return array{total_amount: float, return: float, return_percentage: float}
     */
    public function getPerformance(string $accountNumber): array
    {
        $response = $this->client()->get("/accounts/{$accountNumber}/performance");

        $response->throw();

        return $response->json();
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withHeaders(['X-AUTH-TOKEN' => $this->apiToken])
            ->acceptJson()
            ->throw(function ($response, $exception) {
                Log::error('Indexa Capital API error', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
            });
    }
}
