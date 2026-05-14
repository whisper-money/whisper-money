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
     * @param  string  $keyName  CDP API key name (organizations/{org}/apiKeys/{id}) or Ed25519 key ID (UUID).
     * @param  string  $privateKey  PEM EC private key (ES256) or base64 Ed25519 secret.
     */
    public function __construct(
        private string $keyName,
        private string $privateKey,
    ) {}

    /**
     * ES256 (ECDSA PEM) when keyName is the org/apiKeys path; EdDSA (Ed25519) when keyName is a UUID.
     */
    private function algorithm(): string
    {
        return str_starts_with($this->keyName, 'organizations/') ? 'ES256' : 'EdDSA';
    }

    /**
     * List all brokerage accounts (one per currency) with paginated cursor.
     *
     * @return array<string, mixed>
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
     * @return array<int, array<string, mixed>>
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
     *
     * @api
     */
    public function getProduct(string $productId): array
    {
        return $this->signedRequest('GET', "/api/v3/brokerage/products/{$productId}");
    }

    /**
     * Get best bid/ask for multiple product IDs in one request.
     *
     * @param  array<int, string>  $productIds  e.g. ['BTC-EUR', 'ETH-EUR']
     * @return array<string, mixed>
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
     * Get historical candles for a product.
     *
     * @return array<string, mixed>
     */
    public function getProductCandles(string $productId, int $start, int $end, string $granularity = 'ONE_DAY'): array
    {
        return $this->signedRequest('GET', "/api/v3/brokerage/products/{$productId}/candles", [
            'start' => $start,
            'end' => $end,
            'granularity' => $granularity,
        ]);
    }

    /**
     * Execute a signed JWT request with retry on rate limiting.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function signedRequest(string $method, string $path, array $params = []): array
    {
        return retry(
            self::RETRY_BACKOFF_MS,
            function () use ($method, $path, $params) {
                $jwt = $this->buildJwt($method, $path);

                $request = $this->client($jwt);

                $url = $path;

                if (! empty($params)) {
                    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
                    // Coinbase expects repeated keys without numeric indices: product_ids=A&product_ids=B.
                    $query = preg_replace('/%5B\d+%5D=/', '=', $query);
                    $url .= '?'.$query;
                }

                $response = match (strtoupper($method)) {
                    'GET' => $request->get($url),
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

        return JWT::encode($payload, $this->privateKey, $this->algorithm(), $this->keyName, $headers);
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
