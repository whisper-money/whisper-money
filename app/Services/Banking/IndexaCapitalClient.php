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

        foreach ($userData['accounts'] as $account) {
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
     * Returns an empty array when Indexa Capital responds with 404 (no
     * performance data available yet for the account, e.g. brand-new or
     * inactive accounts) instead of throwing, so the sync can no-op cleanly.
     *
     * @return array{total_amount?: float, return?: float, return_percentage?: float, portfolios?: array<int, array{date?: string, total_amount?: float, return?: float}>, net_amounts?: array<string, float>}
     */
    public function getPerformance(string $accountNumber): array
    {
        $response = $this->client(throwOnError: false)->get("/accounts/{$accountNumber}/performance");

        if ($response->status() === 404) {
            Log::info('No Indexa Capital performance data available', [
                'account_number' => $accountNumber,
            ]);

            return [];
        }

        $response->throw();

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function client(bool $throwOnError = true): PendingRequest
    {
        $client = Http::baseUrl(self::BASE_URL)
            ->withHeaders(['X-AUTH-TOKEN' => $this->apiToken])
            ->acceptJson();

        if (! $throwOnError) {
            return $client;
        }

        return $client->throw(function ($response, $exception) {
            Log::error('Indexa Capital API error', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        });
    }
}
