<?php

namespace App\Services\Banking;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PlaidClient
{
    private const ENV_URLS = [
        'sandbox' => 'https://sandbox.plaid.com',
        'development' => 'https://development.plaid.com',
        'production' => 'https://production.plaid.com',
    ];

    private ?string $accessToken = null;

    private string $baseUrl;
    private string $clientId;
    private string $secret;

    public function __construct(
        ?string $accessToken = null,
        public readonly ?string $itemId = null,
    ) {
        $this->accessToken = $accessToken;
        $this->clientId = config('services.plaid.client_id');
        $this->secret = config('services.plaid.secret');
        $env = config('services.plaid.env', 'sandbox');
        $this->baseUrl = self::ENV_URLS[$env] ?? self::ENV_URLS['sandbox'];
    }

    /**
     * Create a link token for Plaid Link frontend SDK.
     *
     * @param  string  $clientUserId  Your internal user ID (opaque to Plaid).
     * @param  array   $countryCodes  e.g. ['US', 'CA']
     * @return array{link_token: string, expiration: string}
     */
    public function createLinkToken(string $clientUserId, array $countryCodes = ['US']): array
    {
        $response = $this->client()->post('/link/token/create', [
            'user' => [
                'client_user_id' => $clientUserId,
            ],
            'client_name' => 'Whisper Money',
            'products' => ['transactions'],
            'country_codes' => $countryCodes,
            'language' => 'en',
        ]);

        $response->throw();

        return $response->json();
    }

    /**
     * Exchange a Plaid Link public_token for a permanent access_token.
     *
     * @return array{access_token: string, item_id: string, request_id: string}
     */
    public function exchangePublicToken(string $publicToken): array
    {
        $response = $this->client()->post('/item/public_token/exchange', [
            'public_token' => $publicToken,
        ]);

        $response->throw();

        return $response->json();
    }

    /**
     * Fetch transaction updates for an item using Plaid's /transactions/sync.
     *
     * @return array{added: array, modified: array, removed: array, next_cursor: string|null, has_more: bool}
     */
    public function syncTransactions(?string $cursor = null): array
    {
        $payload = [];

        if ($cursor !== null) {
            $payload['cursor'] = $cursor;
        }

        $response = $this->client()->post('/transactions/sync', $payload);

        $response->throw();

        return $response->json();
    }

    /**
     * Fetch account balances.
     *
     * @return array{accounts: array, item: array, request_id: string}
     */
    public function getBalances(): array
    {
        $response = $this->client()->post('/accounts/balance/get');

        $response->throw();

        return $response->json();
    }

    /**
     * Fetch account details.
     *
     * @return array{accounts: array, item: array, request_id: string}
     */
    public function getAccounts(): array
    {
        $response = $this->client()->post('/accounts/get');

        $response->throw();

        return $response->json();
    }

    private function client(): PendingRequest
    {
        $payload = [
            'client_id' => $this->clientId,
            'secret' => $this->secret,
        ];

        if ($this->accessToken !== null) {
            $payload['access_token'] = $this->accessToken;
        }

        return Http::baseUrl($this->baseUrl)
            ->withOptions(['json' => $payload])
            ->acceptJson()
            ->throw(function ($response, RequestException $exception) {
                $body = $response->json();
                $errorCode = $body['error_code'] ?? null;

                Log::warning('Plaid API error', [
                    'status' => $response->status(),
                    'error_code' => $errorCode,
                    'error_message' => $body['error_message'] ?? null,
                ]);
            });
    }
}
