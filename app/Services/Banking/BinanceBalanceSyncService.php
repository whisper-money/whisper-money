<?php

namespace App\Services\Banking;

use App\Models\Account;
use Illuminate\Support\Facades\Log;

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

    /**
     * Sync the total portfolio value for a Binance account.
     */
    public function sync(Account $account, BinanceClient $client): void
    {
        if (! $account->external_account_id) {
            return;
        }

        $accountData = $client->getAccount();
        $balances = $accountData['balances'] ?? [];

        if (empty($balances)) {
            Log::warning('No balance data from Binance', [
                'account_id' => $account->id,
            ]);

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

        Log::info('Synced Binance balance', [
            'account_id' => $account->id,
            'total_value_cents' => $totalValueCents,
            'currency' => $targetCurrency,
        ]);
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
