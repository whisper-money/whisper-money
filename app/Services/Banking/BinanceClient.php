<?php

namespace App\Services\Banking;

use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BinanceClient
{
    private const BASE_URL = 'https://api.binance.com';

    /** @var array<int, int> Retry backoff: 10s, 30s, 60s */
    private const RETRY_BACKOFF_MS = [10_000, 30_000, 60_000];

    public function __construct(
        private string $apiKey,
        private string $apiSecret,
    ) {}

    /**
     * Get account information including balances, omitting zero-balance assets.
     *
     * @return array{balances: array<int, array{asset: string, free: string, locked: string}>}
     */
    public function getAccount(): array
    {
        return $this->signedRequest('/api/v3/account', ['omitZeroBalances' => 'true']);
    }

    /**
     * Get all ticker prices for trading pairs.
     *
     * @return array<int, array{symbol: string, price: string}>
     */
    public function getTickerPrices(): array
    {
        $response = $this->publicClient()->get('/api/v3/ticker/price');

        $response->throw();

        return $response->json();
    }

    /**
     * Get daily account snapshots (up to 30 per request, max 180 days history).
     *
     * @return array{snapshotVos: array<int, array{type: string, updateTime: int, data: array{balances: array<int, array{asset: string, free: string, locked: string}>, totalAssetOfBtc: string}}>}
     */
    public function getAccountSnapshots(int $startTime, int $endTime, int $limit = 30): array
    {
        return $this->signedRequest('/sapi/v1/accountSnapshot', [
            'type' => 'SPOT',
            'startTime' => $startTime,
            'endTime' => $endTime,
            'limit' => $limit,
        ]);
    }

    /**
     * Execute a signed request with fresh timestamp on each retry attempt.
     */
    private function signedRequest(string $path, array $params = []): array
    {
        return retry(
            self::RETRY_BACKOFF_MS,
            function () use ($path, $params) {
                $params['timestamp'] = (int) (microtime(true) * 1000);
                $queryString = http_build_query($params);
                $signature = hash_hmac('sha256', $queryString, $this->apiSecret);

                $response = $this->authenticatedClient()
                    ->get("{$path}?{$queryString}&signature={$signature}");

                $response->throw();

                return $response->json();
            },
            when: fn (Exception $e) => $e instanceof RequestException && $e->response->status() === 429,
        );
    }

    private function authenticatedClient(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withHeaders(['X-MBX-APIKEY' => $this->apiKey])
            ->acceptJson()
            ->throw(function ($response, $exception) {
                Log::error('Binance API error', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
            });
    }

    private function publicClient(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->acceptJson()
            ->retry(
                self::RETRY_BACKOFF_MS,
                fn (Exception $e) => $e instanceof RequestException && $e->response->status() === 429,
            );
    }
}
