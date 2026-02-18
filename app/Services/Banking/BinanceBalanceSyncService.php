<?php

namespace App\Services\Banking;

use App\Models\Account;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

class BinanceBalanceSyncService
{
    /** @var array<string, string> Maps fiat currency codes to Binance quote assets */
    private const FIAT_QUOTE_MAP = [
        'USD' => 'USDT',
        'EUR' => 'EUR',
        'GBP' => 'GBP',
        'JPY' => 'JPY',
        'AUD' => 'AUD',
        'BRL' => 'BRL',
        'TRY' => 'TRY',
    ];

    /** @var array<int, string> Stablecoins pegged 1:1 to USD */
    private const USD_STABLECOINS = ['USDT', 'USDC', 'BUSD', 'FDUSD', 'TUSD'];

    private const SNAPSHOT_MAX_DAYS = 180;

    private const SNAPSHOT_WINDOW_DAYS = 30;

    private const KLINE_CLOSE_INDEX = 4;

    /** Seconds to wait between API calls to avoid hitting Binance rate limits */
    private const THROTTLE_SECONDS = 3;

    /**
     * Sync the total portfolio value for a Binance account.
     * On first sync, fetches up to 180 days of historical snapshots.
     * On subsequent syncs, fetches snapshots since the last recorded balance.
     */
    public function sync(Account $account, BinanceClient $client, bool $isFirstSync = false): void
    {
        if (! $account->external_account_id) {
            return;
        }

        $hadHistoricalSync = $this->syncHistoricalBalances($account, $client, $isFirstSync);

        if ($hadHistoricalSync) {
            Sleep::for(self::THROTTLE_SECONDS)->seconds();
        }

        $this->syncCurrentBalance($account, $client);
    }

    /**
     * Sync today's balance using live ticker prices.
     */
    private function syncCurrentBalance(Account $account, BinanceClient $client): void
    {
        $accountData = $client->getAccount();
        $balances = $accountData['balances'] ?? [];

        if (empty($balances)) {
            return;
        }

        $tickerPrices = $client->getTickerPrices();
        $priceMap = $this->buildPriceMap($tickerPrices);

        $targetCurrency = strtoupper($account->currency_code);
        $totalValueCents = $this->calculateTotalValue($balances, $priceMap, $targetCurrency);

        $account->balances()->updateOrCreate(
            ['balance_date' => now()->toDateString()],
            ['balance' => $totalValueCents],
        );
    }

    /**
     * Fetch historical snapshots and convert each day's balances using daily kline close prices.
     *
     * @return bool Whether any API calls were made
     */
    private function syncHistoricalBalances(Account $account, BinanceClient $client, bool $isFirstSync): bool
    {
        $targetCurrency = strtoupper($account->currency_code);
        $quoteAsset = self::FIAT_QUOTE_MAP[$targetCurrency] ?? 'USDT';

        $endDate = now()->subDay();
        $startDate = $isFirstSync
            ? now()->subDays(self::SNAPSHOT_MAX_DAYS)
            : ($account->balances()->max('balance_date')
                ? Carbon::parse($account->balances()->max('balance_date'))->addDay()
                : now()->subDays(self::SNAPSHOT_MAX_DAYS));

        if ($startDate->greaterThanOrEqualTo($endDate)) {
            return false;
        }

        $snapshots = $this->fetchAllSnapshots($client, $startDate, $endDate);

        if (empty($snapshots)) {
            return true;
        }

        $assets = $this->collectUniqueAssets($snapshots);
        $klineCache = $this->fetchKlinesForAssets($client, $assets, $startDate, $endDate, $targetCurrency, $quoteAsset);

        $count = 0;

        foreach ($snapshots as $snapshot) {
            $updateTime = $snapshot['updateTime'] ?? null;
            $balances = $snapshot['data']['balances'] ?? [];

            if ($updateTime === null || empty($balances)) {
                continue;
            }

            $date = Carbon::createFromTimestampMs($updateTime)->toDateString();
            $totalValueCents = $this->calculateSnapshotValue($balances, $klineCache, $date, $targetCurrency, $quoteAsset);

            $account->balances()->updateOrCreate(
                ['balance_date' => $date],
                ['balance' => $totalValueCents],
            );

            $count++;
        }

        Log::info('Synced Binance historical balances', [
            'account_id' => $account->id,
            'days_synced' => $count,
            'currency' => $targetCurrency,
        ]);

        return true;
    }

    /**
     * Fetch snapshots across multiple 30-day windows.
     *
     * @return array<int, array>
     */
    private function fetchAllSnapshots(BinanceClient $client, Carbon $startDate, Carbon $endDate): array
    {
        $snapshots = [];
        $windowStart = $startDate->copy();
        $isFirst = true;

        while ($windowStart->lessThan($endDate)) {
            if (! $isFirst) {
                Sleep::for(self::THROTTLE_SECONDS)->seconds();
            }
            $isFirst = false;

            $windowEnd = $windowStart->copy()->addDays(self::SNAPSHOT_WINDOW_DAYS)->min($endDate);

            $response = $client->getAccountSnapshots(
                $windowStart->getTimestampMs(),
                $windowEnd->endOfDay()->getTimestampMs(),
                self::SNAPSHOT_WINDOW_DAYS,
            );

            foreach ($response['snapshotVos'] ?? [] as $snapshot) {
                $snapshots[] = $snapshot;
            }

            $windowStart = $windowEnd->copy()->addDay()->startOfDay();
        }

        return $snapshots;
    }

    /**
     * Collect all unique asset names from snapshots that have a non-zero balance.
     *
     * @return array<int, string>
     */
    private function collectUniqueAssets(array $snapshots): array
    {
        $assets = [];

        foreach ($snapshots as $snapshot) {
            foreach ($snapshot['data']['balances'] ?? [] as $balance) {
                $quantity = (float) ($balance['free'] ?? 0) + (float) ($balance['locked'] ?? 0);
                if ($quantity > 0) {
                    $assets[$balance['asset']] = true;
                }
            }
        }

        return array_keys($assets);
    }

    /**
     * Pre-fetch daily kline close prices for all needed trading pairs.
     *
     * @return array<string, array<string, float>> symbol => [date => closePrice]
     */
    private function fetchKlinesForAssets(
        BinanceClient $client,
        array $assets,
        Carbon $startDate,
        Carbon $endDate,
        string $targetCurrency,
        string $quoteAsset,
    ): array {
        $klineCache = [];
        $pairsToFetch = [];

        foreach ($assets as $asset) {
            if ($asset === $targetCurrency) {
                continue;
            }
            if ($targetCurrency === 'USD' && in_array($asset, self::USD_STABLECOINS, true)) {
                continue;
            }

            $pairsToFetch[$asset.$quoteAsset] = true;
            if ($quoteAsset !== 'USDT') {
                $pairsToFetch[$asset.'USDT'] = true;
                $pairsToFetch[$quoteAsset.'USDT'] = true;
            }
        }

        $startMs = $startDate->getTimestampMs();
        $endMs = $endDate->endOfDay()->getTimestampMs();
        $isFirst = true;

        foreach (array_keys($pairsToFetch) as $symbol) {
            if (! $isFirst) {
                Sleep::for(self::THROTTLE_SECONDS)->seconds();
            }
            $isFirst = false;

            try {
                $klines = $client->getDailyKlines($symbol, $startMs, $endMs);
                foreach ($klines as $kline) {
                    $date = Carbon::createFromTimestampMs($kline[0])->toDateString();
                    $klineCache[$symbol][$date] = (float) $kline[self::KLINE_CLOSE_INDEX];
                }
            } catch (\Throwable) {
                // Pair may not exist on Binance, skip it
            }
        }

        return $klineCache;
    }

    /**
     * Calculate the total value of a snapshot's balances using kline close prices for that date.
     */
    private function calculateSnapshotValue(
        array $balances,
        array $klineCache,
        string $date,
        string $targetCurrency,
        string $quoteAsset,
    ): int {
        $totalValue = 0.0;

        foreach ($balances as $balance) {
            $asset = $balance['asset'];
            $quantity = (float) ($balance['free'] ?? 0) + (float) ($balance['locked'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            $priceMap = [];
            foreach ($klineCache as $symbol => $dates) {
                if (isset($dates[$date])) {
                    $priceMap[$symbol] = $dates[$date];
                }
            }

            $totalValue += $this->convertAssetToFiat($asset, $quantity, $priceMap, $targetCurrency, $quoteAsset);
        }

        return (int) round($totalValue * 100);
    }

    /**
     * Build a lookup map of symbol => price from ticker data.
     *
     * @param  array<int, array{symbol: string, price: string}>  $tickerPrices
     * @return array<string, float>
     */
    private function buildPriceMap(array $tickerPrices): array
    {
        $map = [];
        foreach ($tickerPrices as $ticker) {
            $map[$ticker['symbol']] = (float) $ticker['price'];
        }

        return $map;
    }

    /**
     * Calculate the total portfolio value in the target fiat currency (in cents).
     *
     * @param  array<int, array{asset: string, free: string, locked: string}>  $balances
     * @param  array<string, float>  $priceMap
     */
    private function calculateTotalValue(array $balances, array $priceMap, string $targetCurrency): int
    {
        $quoteAsset = self::FIAT_QUOTE_MAP[$targetCurrency] ?? 'USDT';
        $totalValue = 0.0;

        foreach ($balances as $balance) {
            $asset = $balance['asset'];
            $quantity = (float) $balance['free'] + (float) $balance['locked'];

            if ($quantity <= 0) {
                continue;
            }

            $value = $this->convertAssetToFiat($asset, $quantity, $priceMap, $targetCurrency, $quoteAsset);
            $totalValue += $value;
        }

        return (int) round($totalValue * 100);
    }

    /**
     * Convert a single asset's quantity to fiat value.
     */
    private function convertAssetToFiat(
        string $asset,
        float $quantity,
        array $priceMap,
        string $targetCurrency,
        string $quoteAsset,
    ): float {
        // Asset IS the target currency (e.g., EUR balance when target is EUR)
        if ($asset === $targetCurrency) {
            return $quantity;
        }

        // USD stablecoins when target is USD → 1:1
        if ($targetCurrency === 'USD' && in_array($asset, self::USD_STABLECOINS, true)) {
            return $quantity;
        }

        // Direct pair exists (e.g., BTCEUR when target is EUR)
        $directPair = $asset.$quoteAsset;
        if (isset($priceMap[$directPair])) {
            return $quantity * $priceMap[$directPair];
        }

        // Fallback: convert via USDT (e.g., BTCUSDT * quantity / EURUSDT)
        $usdtPair = $asset.'USDT';
        $fiatUsdtPair = $quoteAsset.'USDT';

        if (isset($priceMap[$usdtPair])) {
            $valueInUsdt = $quantity * $priceMap[$usdtPair];

            // If target is already USD/USDT, no further conversion needed
            if ($quoteAsset === 'USDT') {
                return $valueInUsdt;
            }

            // Convert USDT to target fiat
            if (isset($priceMap[$fiatUsdtPair]) && $priceMap[$fiatUsdtPair] > 0) {
                return $valueInUsdt / $priceMap[$fiatUsdtPair];
            }
        }

        Log::warning('Could not convert Binance asset to fiat', [
            'asset' => $asset,
            'target_currency' => $targetCurrency,
        ]);

        return 0.0;
    }
}
