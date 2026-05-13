<?php

namespace App\Services\Banking;

use App\Models\Account;
use App\Services\CurrencyConversionService;
use Illuminate\Support\Facades\Log;

class CoinbaseBalanceSyncService
{
    /** @var array<int, string> Stablecoins pegged 1:1 to USD */
    private const USD_STABLECOINS = ['USDT', 'USDC', 'DAI', 'PYUSD', 'GUSD'];

    private const USD_CURRENCY = 'USD';

    public function __construct(private CurrencyConversionService $currencyConverter) {}

    /**
     * Sync the total portfolio value for a Coinbase account.
     * Aggregates every wallet balance (crypto + fiat) into the user's fiat currency.
     *
     * @api
     */
    public function sync(Account $account, CoinbaseClient $client): void
    {
        if (! $account->external_account_id) {
            return;
        }

        $this->syncCurrentBalance($account, $client);
    }

    /**
     * Sync today's balance by listing every Coinbase account and converting to target currency.
     */
    public function syncCurrentBalance(Account $account, CoinbaseClient $client): void
    {
        $targetCurrency = strtoupper($account->currency_code);
        $coinbaseAccounts = $client->getAllAccounts();

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
     * Split Coinbase accounts into fiat (converted directly) and crypto holdings.
     *
     * @param  array<int, array<string, mixed>>  $coinbaseAccounts
     * @return array{0: float, 1: array<string, float>}
     */
    private function partitionBalances(array $coinbaseAccounts, string $targetCurrency): array
    {
        $fiatTotal = 0.0;
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
                $fiatTotal += $this->convertFiat($currency, $balance, $targetCurrency);

                continue;
            }

            $cryptoAssets[$currency] = ($cryptoAssets[$currency] ?? 0.0) + $balance;
        }

        return [$fiatTotal, $cryptoAssets];
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
