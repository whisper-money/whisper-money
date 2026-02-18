<?php

namespace App\Services\Banking;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BinanceClient
{
    private const BASE_URL = 'https://api.binance.com';

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
        $timestamp = (int) (microtime(true) * 1000);
        $queryString = "omitZeroBalances=true&timestamp={$timestamp}";
        $signature = hash_hmac('sha256', $queryString, $this->apiSecret);

        $response = $this->client()->get("/api/v3/account?{$queryString}&signature={$signature}");

        $response->throw();

        return $response->json();
    }

    /**
     * Get all ticker prices for trading pairs.
     *
     * @return array<int, array{symbol: string, price: string}>
     */
    public function getTickerPrices(): array
    {
        $response = Http::baseUrl(self::BASE_URL)
            ->acceptJson()
            ->get('/api/v3/ticker/price');

        $response->throw();

        return $response->json();
    }

    private function client(): PendingRequest
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
}
