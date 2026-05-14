<?php

namespace App\Services\Banking;

use App\Models\Account;
use App\Services\CurrencyConversionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class CoinbaseBalanceSyncService
{
    /** @var array<int, string> Stablecoins pegged 1:1 to USD */
    private const USD_STABLECOINS = ['USDT', 'USDC', 'DAI', 'PYUSD', 'GUSD'];

    private const USD_CURRENCY = 'USD';

    private const HISTORICAL_MONTHS = 12;

    private const CANDLE_WINDOW_DAYS = 300;

    public function __construct(private CurrencyConversionService $currencyConverter) {}

    /**
     * Sync the total portfolio value for a Coinbase account.
     * Aggregates every wallet balance (crypto + fiat) into the user's fiat currency.
     *
     * @api
     */
    public function sync(Account $account, CoinbaseClient $client, bool $isFirstSync = false, bool $backfillMissingHistory = false): void
    {
        if (! $account->external_account_id) {
            return;
        }

        $coinbaseAccounts = $client->getAllAccounts();

        if (empty($coinbaseAccounts)) {
            return;
        }

        if ($isFirstSync || ($backfillMissingHistory && $this->needsHistoricalBackfill($account))) {
            $this->syncHistoricalBalances($account, $client, $coinbaseAccounts);
        }

        $this->syncCurrentBalance($account, $client, $coinbaseAccounts);
    }

    /**
     * Sync today's balance by listing every Coinbase account and converting to target currency.
     *
     * @param  array<int, array<string, mixed>>|null  $coinbaseAccounts
     */
    public function syncCurrentBalance(Account $account, CoinbaseClient $client, ?array $coinbaseAccounts = null): void
    {
        $targetCurrency = strtoupper($account->currency_code);
        $coinbaseAccounts ??= $client->getAllAccounts();

        if (empty($coinbaseAccounts)) {
            return;
        }

        [$fiatTotal, $cryptoAssets] = $this->partitionBalances($coinbaseAccounts, $targetCurrency);

        $priceMap = $this->fetchPriceMap($client, array_keys($cryptoAssets), $targetCurrency);

        $cryptoTotal = $this->convertCryptoAssets($cryptoAssets, $priceMap, $targetCurrency);

        $totalValueCents = (int) round(($fiatTotal + $cryptoTotal) * 100);

        $account->balances()->updateOrCreate(
            ['balance_date' => now()->toDateString()],
            ['balance' => $totalValueCents],
        );
    }

    /**
     * Backfill one year of monthly balances using current holdings valued at each month's matching day close.
     *
     * @param  array<int, array<string, mixed>>  $coinbaseAccounts
     */
    public function syncHistoricalBalances(Account $account, CoinbaseClient $client, array $coinbaseAccounts): void
    {
        $targetCurrency = strtoupper($account->currency_code);
        $historicalDates = $this->historicalDates();

        if ($historicalDates === []) {
            return;
        }

        $startDate = $historicalDates[0];
        $endDate = $historicalDates[array_key_last($historicalDates)];

        [$fiatBalances, $cryptoAssets] = $this->partitionBalancesByCurrency($coinbaseAccounts);
        $priceHistory = $this->fetchHistoricalPriceMaps($client, array_keys($cryptoAssets), $targetCurrency, $startDate, $endDate);
        $count = 0;

        foreach ($historicalDates as $date) {
            $dateString = $date->toDateString();
            $totalValue = $this->convertHistoricalFiatBalances($fiatBalances, $targetCurrency, $dateString);
            $totalValue += $this->convertHistoricalCryptoAssets($cryptoAssets, $priceHistory, $targetCurrency, $dateString);

            if ($totalValue <= 0) {
                continue;
            }

            $account->balances()->updateOrCreate(
                ['balance_date' => $dateString],
                ['balance' => (int) round($totalValue * 100)],
            );

            $count++;
        }

        Log::info('Synced Coinbase historical balances', [
            'account_id' => $account->id,
            'days_synced' => $count,
            'currency' => $targetCurrency,
        ]);
    }

    private function needsHistoricalBackfill(Account $account): bool
    {
        return ! $account->balances()
            ->where('balance_date', '<=', $this->historicalStartDate()->toDateString())
            ->exists();
    }

    private function historicalStartDate(): Carbon
    {
        return now()->subMonthsNoOverflow(self::HISTORICAL_MONTHS)->startOfDay();
    }

    /**
     * @return array<int, Carbon>
     */
    private function historicalDates(): array
    {
        return collect(range(self::HISTORICAL_MONTHS, 1))
            ->map(fn (int $monthsAgo): Carbon => now()->subMonthsNoOverflow($monthsAgo)->startOfDay())
            ->all();
    }

    /**
     * Split Coinbase accounts into fiat (converted directly) and crypto holdings.
     *
     * @param  array<int, array<string, mixed>>  $coinbaseAccounts
     * @return array{0: float, 1: array<string, float>}
     */
    private function partitionBalances(array $coinbaseAccounts, string $targetCurrency): array
    {
        [$fiatBalances, $cryptoAssets] = $this->partitionBalancesByCurrency($coinbaseAccounts);

        $fiatTotal = 0.0;

        foreach ($fiatBalances as $currency => $balance) {
            $fiatTotal += $this->convertFiat($currency, $balance, $targetCurrency);
        }

        return [$fiatTotal, $cryptoAssets];
    }

    /**
     * @param  array<int, array<string, mixed>>  $coinbaseAccounts
     * @return array{0: array<string, float>, 1: array<string, float>}
     */
    private function partitionBalancesByCurrency(array $coinbaseAccounts): array
    {
        $fiatBalances = [];
        $cryptoAssets = [];

        foreach ($coinbaseAccounts as $coinbaseAccount) {
            $currency = strtoupper($coinbaseAccount['currency'] ?? '');
            $available = (float) ($coinbaseAccount['available_balance']['value'] ?? 0);
            $hold = (float) ($coinbaseAccount['hold']['value'] ?? 0);
            $balance = $available + $hold;

            if ($currency === '' || $balance <= 0) {
                continue;
            }

            if ($this->isFiatCurrency($currency)) {
                $fiatBalances[$currency] = ($fiatBalances[$currency] ?? 0.0) + $balance;

                continue;
            }

            $cryptoAssets[$currency] = ($cryptoAssets[$currency] ?? 0.0) + $balance;
        }

        return [$fiatBalances, $cryptoAssets];
    }

    private function convertFiat(string $currency, float $amount, string $targetCurrency): float
    {
        if ($currency === $targetCurrency) {
            return $amount;
        }

        return $this->currencyConverter->convert(
            $currency,
            $targetCurrency,
            $amount,
            now()->toDateString(),
        );
    }

    /**
     * Build a price map (asset => price in target currency) using batched best_bid_ask.
     *
     * @param  array<int, string>  $assets
     * @return array<string, float>
     */
    private function fetchPriceMap(CoinbaseClient $client, array $assets, string $targetCurrency): array
    {
        $productIds = array_map(fn (string $asset) => "{$asset}-{$targetCurrency}", $assets);

        if (empty($productIds)) {
            return [];
        }

        try {
            $response = $client->getBestBidAsk($productIds);
        } catch (\Throwable $e) {
            Log::warning('Coinbase best_bid_ask failed, falling back to per-asset USD conversion', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $map = [];

        foreach ($response['pricebooks'] ?? [] as $pricebook) {
            $productId = $pricebook['product_id'] ?? '';
            $bid = (float) ($pricebook['bids'][0]['price'] ?? 0);
            $ask = (float) ($pricebook['asks'][0]['price'] ?? 0);

            if ($productId === '') {
                continue;
            }

            $asset = explode('-', $productId)[0];

            if ($bid > 0 && $ask > 0) {
                $map[$asset] = ($bid + $ask) / 2;
            } elseif ($bid > 0) {
                $map[$asset] = $bid;
            } elseif ($ask > 0) {
                $map[$asset] = $ask;
            }
        }

        return $map;
    }

    /**
     * Fetch daily close prices keyed by asset and date.
     *
     * @param  array<int, string>  $assets
     * @return array<string, array<string, float>>
     */
    private function fetchHistoricalPriceMaps(CoinbaseClient $client, array $assets, string $targetCurrency, Carbon $startDate, Carbon $endDate): array
    {
        $priceHistory = [];

        foreach ($assets as $asset) {
            if (in_array($asset, self::USD_STABLECOINS, true)) {
                continue;
            }

            $priceHistory[$asset] = $this->fetchHistoricalPricesForAsset($client, $asset, $targetCurrency, $startDate, $endDate);
        }

        return $priceHistory;
    }

    /**
     * @return array<string, float>
     */
    private function fetchHistoricalPricesForAsset(CoinbaseClient $client, string $asset, string $targetCurrency, Carbon $startDate, Carbon $endDate): array
    {
        $directPrices = $this->fetchHistoricalProductPrices($client, "{$asset}-{$targetCurrency}", $startDate, $endDate);

        if ($directPrices !== [] || $targetCurrency === self::USD_CURRENCY) {
            return $directPrices;
        }

        $usdPrices = $this->fetchHistoricalProductPrices($client, "{$asset}-".self::USD_CURRENCY, $startDate, $endDate);
        $targetPrices = [];

        foreach ($usdPrices as $date => $usdPrice) {
            $targetPrice = $this->currencyConverter->convert(self::USD_CURRENCY, $targetCurrency, $usdPrice, $date);

            if ($targetPrice > 0) {
                $targetPrices[$date] = $targetPrice;
            }
        }

        return $targetPrices;
    }

    /**
     * @return array<string, float>
     */
    private function fetchHistoricalProductPrices(CoinbaseClient $client, string $productId, Carbon $startDate, Carbon $endDate): array
    {
        $prices = [];

        foreach ($this->fetchProductCandles($client, $productId, $startDate, $endDate) as $candle) {
            $timestamp = $candle['start'] ?? null;
            $close = (float) ($candle['close'] ?? 0);

            if ($timestamp === null || $close <= 0) {
                continue;
            }

            $prices[Carbon::createFromTimestamp((int) $timestamp)->toDateString()] = $close;
        }

        return $prices;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchProductCandles(CoinbaseClient $client, string $productId, Carbon $startDate, Carbon $endDate): array
    {
        $candles = [];
        $windowStart = $startDate->copy();

        while ($windowStart->lessThanOrEqualTo($endDate)) {
            $windowEnd = $windowStart->copy()->addDays(self::CANDLE_WINDOW_DAYS)->min($endDate);

            try {
                $response = $client->getProductCandles(
                    $productId,
                    $windowStart->getTimestamp(),
                    $windowEnd->copy()->endOfDay()->getTimestamp(),
                );
            } catch (\Throwable $e) {
                Log::warning('Coinbase historical candles failed', [
                    'product_id' => $productId,
                    'start' => $windowStart->toDateString(),
                    'end' => $windowEnd->toDateString(),
                    'error' => $e->getMessage(),
                ]);

                break;
            }

            foreach ($response['candles'] ?? [] as $candle) {
                $candles[] = $candle;
            }

            $windowStart = $windowEnd->copy()->addDay()->startOfDay();
        }

        return $candles;
    }

    /**
     * @param  array<string, float>  $fiatBalances
     */
    private function convertHistoricalFiatBalances(array $fiatBalances, string $targetCurrency, string $date): float
    {
        $total = 0.0;

        foreach ($fiatBalances as $currency => $amount) {
            $total += $this->convertFiatOnDate($currency, $amount, $targetCurrency, $date);
        }

        return $total;
    }

    private function convertFiatOnDate(string $currency, float $amount, string $targetCurrency, string $date): float
    {
        if ($currency === $targetCurrency) {
            return $amount;
        }

        return $this->currencyConverter->convert($currency, $targetCurrency, $amount, $date);
    }

    /**
     * @param  array<string, float>  $cryptoAssets
     * @param  array<string, array<string, float>>  $priceHistory
     */
    private function convertHistoricalCryptoAssets(array $cryptoAssets, array $priceHistory, string $targetCurrency, string $date): float
    {
        $total = 0.0;

        foreach ($cryptoAssets as $asset => $quantity) {
            if (in_array($asset, self::USD_STABLECOINS, true)) {
                $total += $this->convertFiatOnDate(self::USD_CURRENCY, $quantity, $targetCurrency, $date);

                continue;
            }

            $price = $priceHistory[$asset][$date] ?? null;

            if ($price !== null) {
                $total += $quantity * $price;

                continue;
            }

            $total += $this->currencyConverter->convert($asset, $targetCurrency, $quantity, $date);
        }

        return $total;
    }

    /**
     * Convert each crypto holding to target fiat. Falls back via USD pair + currency converter.
     *
     * @param  array<string, float>  $cryptoAssets
     * @param  array<string, float>  $priceMap
     */
    private function convertCryptoAssets(array $cryptoAssets, array $priceMap, string $targetCurrency): float
    {
        $total = 0.0;

        foreach ($cryptoAssets as $asset => $quantity) {
            if (in_array($asset, self::USD_STABLECOINS, true)) {
                $total += $this->convertFiat(self::USD_CURRENCY, $quantity, $targetCurrency);

                continue;
            }

            if (isset($priceMap[$asset])) {
                $total += $quantity * $priceMap[$asset];

                continue;
            }

            $converted = $this->currencyConverter->convert(
                $asset,
                $targetCurrency,
                $quantity,
                now()->toDateString(),
            );

            if ($converted > 0) {
                $total += $converted;

                continue;
            }

            Log::warning('Could not price Coinbase asset', [
                'asset' => $asset,
                'target_currency' => $targetCurrency,
                'quantity' => $quantity,
            ]);
        }

        return $total;
    }

    /**
     * Heuristic: ISO 4217 fiat codes are 3 letters; Coinbase exposes them like USD/EUR/GBP.
     * Stablecoins are not fiat (priced via crypto pairs).
     */
    private function isFiatCurrency(string $currency): bool
    {
        static $fiats = ['USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'CNY', 'NZD', 'SEK', 'NOK', 'DKK', 'BRL', 'TRY', 'MXN', 'ZAR', 'SGD', 'HKD', 'PLN'];

        return in_array($currency, $fiats, true);
    }
}
