<?php

namespace App\Services\Banking;

use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BitpandaClient
{
    private const BASE_URL = 'https://api.bitpanda.com/v1';

    /** @var array<int, int> Retry backoff: 10s, 30s, 60s */
    private const RETRY_BACKOFF_MS = [10_000, 30_000, 60_000];

    public function __construct(
        private string $apiKey,
    ) {}

    /**
     * List all crypto wallets with balances.
     *
     * @return array{data: array<int, array{type: string, id: string, attributes: array{cryptocoin_id: string, cryptocoin_symbol: string, balance: string, is_default: bool, name: string, deleted: bool}}>}
     */
    public function getCryptoWallets(): array
    {
        return $this->get('/wallets');
    }

    /**
     * List all fiat wallets with balances.
     *
     * @return array{data: array<int, array{type: string, id: string, attributes: array{fiat_id: string, fiat_symbol: string, balance: string, name: string}}>}
     */
    public function getFiatWallets(): array
    {
        return $this->get('/fiatwallets');
    }

    /**
     * List all asset wallets (crypto + commodity) grouped by type.
     *
     * @return array<string, mixed>
     */
    public function getAssetWallets(): array
    {
        return $this->get('/asset-wallets');
    }

    /**
     * List trades with optional cursor-based pagination.
     *
     * @return array{data: array<int, array{type: string, id: string, attributes: array}>, meta: array, links: array}
     */
    public function getTrades(?string $cursor = null, int $pageSize = 25): array
    {
        $params = ['page_size' => $pageSize];

        if ($cursor) {
            $params['cursor'] = $cursor;
        }

        return $this->get('/trades', $params);
    }

    /**
     * Execute an authenticated GET request with retry on rate limiting.
     */
    private function get(string $path, array $params = []): array
    {
        return retry(
            self::RETRY_BACKOFF_MS,
            function () use ($path, $params) {
                $response = $this->client()->get($path, $params);

                $response->throw();

                return $response->json();
            },
            when: fn (Exception $e) => $e instanceof RequestException && $e->response->status() === 429,
        );
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withHeaders(['X-Api-Key' => $this->apiKey])
            ->acceptJson()
            ->throw(function ($response, $exception) {
                Log::error('Bitpanda API error', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
            });
    }
}
