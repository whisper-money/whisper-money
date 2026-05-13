<?php

namespace App\Services\Banking;

use Exception;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CoinbaseClient
{
    private const BASE_URL = 'https://api.coinbase.com';

    private const HOST = 'api.coinbase.com';

    /** @var array<int, int> Retry backoff: 10s, 30s, 60s */
    private const RETRY_BACKOFF_MS = [10_000, 30_000, 60_000];

    private const JWT_TTL_SECONDS = 120;

    /**
     * @param  string  $keyName  CDP API key name, e.g. organizations/{org}/apiKeys/{id}
     * @param  string  $privateKey  PEM-encoded EC private key
     */
    public function __construct(
        private string $keyName,
        private string $privateKey,
    ) {}

    /**
     * List all brokerage accounts (one per currency) with paginated cursor.
     *
     * @return array{accounts: array<int, array{uuid: string, name: string, currency: string, available_balance: array{value: string, currency: string}, hold: array{value: string, currency: string}, active: bool, type: string}>, has_next: bool, cursor: string, size: int}
     */
    public function getAccounts(?string $cursor = null, int $limit = 250): array
    {
        $params = ['limit' => $limit];

        if ($cursor !== null && $cursor !== '') {
            $params['cursor'] = $cursor;
        }

        return $this->signedRequest('GET', '/api/v3/brokerage/accounts', $params);
    }

    /**
     * Fetch every account by paginating through the cursor.
     *
     * @return array<int, array{uuid: string, name: string, currency: string, available_balance: array{value: string, currency: string}, hold: array{value: string, currency: string}, active: bool, type: string}>
     */
    public function getAllAccounts(): array
    {
        $all = [];
        $cursor = null;

        do {
            $response = $this->getAccounts($cursor);
            $batch = $response['accounts'] ?? [];

            foreach ($batch as $account) {
                $all[] = $account;
            }

            $cursor = $response['has_next'] ?? false ? ($response['cursor'] ?? null) : null;
        } while ($cursor);

        return $all;
    }

    /**
     * Get the latest spot price for a single product (e.g. BTC-EUR).
     *
     * @return array<string, mixed>
     */
    public function getProduct(string $productId): array
    {
        return $this->signedRequest('GET', "/api/v3/brokerage/products/{$productId}");
    }

    /**
     * Get best bid/ask for multiple product IDs in one request.
     *
     * @param  array<int, string>  $productIds  e.g. ['BTC-EUR', 'ETH-EUR']
     * @return array{pricebooks: array<int, array{product_id: string, bids: array<int, array{price: string, size: string}>, asks: array<int, array{price: string, size: string}>}>}
     */
    public function getBestBidAsk(array $productIds): array
    {
        $params = [];

        foreach ($productIds as $productId) {
            $params['product_ids'][] = $productId;
        }

        return $this->signedRequest('GET', '/api/v3/brokerage/best_bid_ask', $params);
    }

    /**
     * Execute a signed JWT request with retry on rate limiting.
     */
    private function signedRequest(string $method, string $path, array $params = []): array
    {
        return retry(
            self::RETRY_BACKOFF_MS,
            function () use ($method, $path, $params) {
                $jwt = $this->buildJwt($method, $path);

                $request = $this->client($jwt);

                $response = match (strtoupper($method)) {
                    'GET' => $request->get($path, $params),
                    default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
                };

                $response->throw();

                return $response->json();
            },
            when: fn (Exception $e) => $e instanceof RequestException && $e->response->status() === 429,
        );
    }

    /**
     * Build a Coinbase CDP JWT for a single request.
     *
     * Claims: sub=keyName, iss=cdp, nbf=now, exp=now+120, uri="METHOD host/path".
     * Header includes kid=keyName and a random nonce.
     */
    private function buildJwt(string $method, string $path): string
    {
        $now = time();

        $payload = [
            'sub' => $this->keyName,
            'iss' => 'cdp',
            'nbf' => $now,
            'exp' => $now + self::JWT_TTL_SECONDS,
            'uri' => strtoupper($method).' '.self::HOST.$path,
        ];

        $headers = ['nonce' => bin2hex(random_bytes(16))];

        return JWT::encode($payload, $this->privateKey, 'ES256', $this->keyName, $headers);
    }

    private function client(string $jwt): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($jwt)
            ->acceptJson()
            ->throw(function ($response) {
                Log::error('Coinbase API error', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
            });
    }
}
