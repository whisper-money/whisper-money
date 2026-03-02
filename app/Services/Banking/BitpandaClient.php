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
     * Get Bitpanda's own ticker prices for all assets.
     * Returns a map of asset symbol to fiat prices (e.g., BTC => {EUR => "56911.68", USD => "67150.45"}).
     * This is a public endpoint — no authentication required.
     *
     * @return array<string, array<string, string>>
     */
    public function getTickerPrices(): array
    {
        return $this->get('/ticker');
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
     * List fiat wallet transactions with optional type filtering and cursor-based pagination.
     *
     * @param  string|null  $type  Filter by type: buy, sell, deposit, withdrawal, transfer, refund
     * @param  string|null  $cursor  Cursor for pagination
     * @param  int  $pageSize  Number of results per page
     * @return array{data: array<int, array{type: string, id: string, attributes: array}>, meta: array, links: array}
     */
    public function getFiatTransactions(?string $type = null, ?string $cursor = null, int $pageSize = 25): array
    {
        $params = ['page_size' => $pageSize];

        if ($type) {
            $params['type'] = $type;
        }

        if ($cursor) {
            $params['cursor'] = $cursor;
        }

        return $this->get('/fiatwallets/transactions', $params);
    }

    /**
     * Fetch all fiat transactions of a given type by paginating through the cursor-based API.
     * Transactions are returned newest-first from the API but this method
     * reverses them to chronological order (oldest first).
     *
     * @param  string  $type  Transaction type: deposit, withdrawal, etc.
     * @return array<int, array{type: string, id: string, attributes: array}>
     */
    public function getAllFiatTransactions(string $type): array
    {
        $allTransactions = [];
        $cursor = null;

        do {
            $response = $this->getFiatTransactions($type, $cursor, 100);
            $transactions = $response['data'];

            if (empty($transactions)) {
                break;
            }

            foreach ($transactions as $transaction) {
                $allTransactions[] = $transaction;
            }

            $cursor = $response['meta']['next_cursor'] ?? null;
        } while ($cursor);

        return array_reverse($allTransactions);
    }

    /**
     * Fetch all trades by paginating through the cursor-based API.
     * Trades are returned newest-first from the API but this method
     * reverses them to chronological order (oldest first).
     *
     * @param  string|null  $sinceDate  Optional ISO date string (YYYY-MM-DD) to stop fetching older trades
     * @return array<int, array{type: string, id: string, attributes: array}>
     */
    public function getAllTrades(?string $sinceDate = null): array
    {
        $allTrades = [];
        $cursor = null;
        $sinceTimestamp = $sinceDate ? strtotime($sinceDate) : null;

        do {
            $response = $this->getTrades($cursor, 100);
            $trades = $response['data'];

            if (empty($trades)) {
                break;
            }

            foreach ($trades as $trade) {
                $tradeTimestamp = (int) ($trade['attributes']['time']['unix'] ?? 0);

                if ($sinceTimestamp && $tradeTimestamp < $sinceTimestamp) {
                    return array_reverse($allTrades);
                }

                $allTrades[] = $trade;
            }

            $cursor = $response['meta']['next_cursor'] ?? null;
        } while ($cursor);

        return array_reverse($allTrades);
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
