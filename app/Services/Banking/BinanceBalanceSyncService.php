<?php

namespace App\Services\Banking;

use App\Models\Account;
use App\Services\CurrencyConversionService;
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

    /** Max days per deposit/withdrawal history request window */
    private const DEPOSIT_WINDOW_DAYS = 90;

    /** Seconds to wait between API calls to avoid hitting Binance rate limits */
    private const THROTTLE_SECONDS = 1;

    private const USD_CURRENCY = 'USD';

    public function __construct(private CurrencyConversionService $currencyConverter) {}

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

        $investedAmountCents = null;

        if ($isFirstSync) {
            $investedAmountCents = $this->calculateInvestedAmount($account, $client);
            Sleep::for(self::THROTTLE_SECONDS)->seconds();
        } else {
            $investedAmountCents = $this->getLastInvestedAmount($account);
        }

        $this->syncCurrentBalance($account, $client, $investedAmountCents);
    }

    /**
     * Sync today's balance using live ticker prices.
     */
    public function syncCurrentBalance(Account $account, BinanceClient $client, ?int $investedAmountCents = null): void
    {
        $accountData = $client->getAccount();
        $balances = $accountData['balances'];

        if (empty($balances)) {
            return;
        }

        $tickerPrices = $client->getTickerPrices();
        $priceMap = $this->buildPriceMap($tickerPrices);

        $targetCurrency = strtoupper($account->currency_code);
        $totalValueCents = $this->calculateTotalValue($balances, $priceMap, $targetCurrency);

        $account->balances()->updateOrCreate(
            ['balance_date' => now()->toDateString()],
            [
                'balance' => $totalValueCents,
                ...($investedAmountCents !== null ? ['invested_amount' => $investedAmountCents] : []),
            ],
        );
    }

    /**
     * Fetch historical snapshots and convert each day's balances using the currency conversion API.
     *
     * @return bool Whether any API calls were made
     */
    public function syncHistoricalBalances(Account $account, BinanceClient $client, bool $isFirstSync): bool
    {
        $targetCurrency = strtoupper($account->currency_code);

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

        $count = 0;
        $skippedAssets = [];

        foreach ($snapshots as $snapshot) {
            $updateTime = $snapshot['updateTime'] ?? null;
            $balances = $snapshot['data']['balances'] ?? [];

            if ($updateTime === null || empty($balances)) {
                continue;
            }

            $date = Carbon::createFromTimestampMs($updateTime)->toDateString();
            $totalValue = 0.0;

            foreach ($balances as $balance) {
                $asset = $balance['asset'];
                $quantity = (float) ($balance['free'] ?? 0) + (float) ($balance['locked'] ?? 0);

                if ($quantity <= 0 || isset($skippedAssets[$asset])) {
                    continue;
                }

                $converted = $this->currencyConverter->convert($asset, $targetCurrency, $quantity, $date);

                if ($converted == 0.0) {
                    $skippedAssets[$asset] = true;

                    continue;
                }

                $totalValue += $converted;
            }

            $account->balances()->updateOrCreate(
                ['balance_date' => $date],
                ['balance' => (int) round($totalValue * 100)],
            );

            $count++;
        }

        Log::info('Synced Binance historical balances', [
            'account_id' => $account->id,
            'days_synced' => $count,
            'currency' => $targetCurrency,
            ...($skippedAssets ? ['skipped_assets' => array_keys($skippedAssets)] : []),
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

            foreach ($response['snapshotVos'] as $snapshot) {
                $snapshots[] = $snapshot;
            }

            $windowStart = $windowEnd->copy()->addDay()->startOfDay();
        }

        return $snapshots;
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
        $totalValue = 0.0;

        foreach ($balances as $balance) {
            $asset = $balance['asset'];
            $quantity = (float) $balance['free'] + (float) $balance['locked'];

            if ($quantity <= 0) {
                continue;
            }

            $value = $this->convertAssetToFiat($asset, $quantity, $priceMap, $targetCurrency);
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
    ): float {
        // Asset IS the target currency (e.g., EUR balance when target is EUR)
        if ($asset === $targetCurrency) {
            return $quantity;
        }

        $quoteAsset = self::FIAT_QUOTE_MAP[$targetCurrency] ?? null;

        if ($quoteAsset !== null) {
            $tickerValue = $this->convertAssetUsingTickerPairs($asset, $quantity, $priceMap, $targetCurrency, $quoteAsset);

            if ($tickerValue !== null) {
                return $tickerValue;
            }
        }

        $usdValue = $this->convertAssetToUsd($asset, $quantity, $priceMap);

        if ($usdValue !== null) {
            if ($targetCurrency === self::USD_CURRENCY) {
                return $usdValue;
            }

            return $this->currencyConverter->convert(
                self::USD_CURRENCY,
                $targetCurrency,
                $usdValue,
                now()->toDateString(),
            );
        }

        $converted = $this->currencyConverter->convert(
            $asset,
            $targetCurrency,
            $quantity,
            now()->toDateString(),
        );

        if ($converted > 0) {
            return $converted;
        }

        Log::warning('Could not convert Binance asset to fiat', [
            'asset' => $asset,
            'target_currency' => $targetCurrency,
        ]);

        return 0.0;
    }

    private function convertAssetUsingTickerPairs(
        string $asset,
        float $quantity,
        array $priceMap,
        string $targetCurrency,
        string $quoteAsset,
    ): ?float {

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

        return null;
    }

    private function convertAssetToUsd(string $asset, float $quantity, array $priceMap): ?float
    {
        if ($asset === self::USD_CURRENCY || in_array($asset, self::USD_STABLECOINS, true)) {
            return $quantity;
        }

        $usdtPair = $asset.'USDT';

        if (isset($priceMap[$usdtPair])) {
            return $quantity * $priceMap[$usdtPair];
        }

        $converted = $this->currencyConverter->convert(
            $asset,
            self::USD_CURRENCY,
            $quantity,
            now()->toDateString(),
        );

        return $converted > 0 ? $converted : null;
    }

    /**
     * Get the last stored invested amount for the account.
     */
    private function getLastInvestedAmount(Account $account): ?int
    {
        return $account->balances()
            ->whereNotNull('invested_amount')
            ->latest('balance_date')
            ->value('invested_amount');
    }

    /**
     * Calculate the net invested amount by fetching all deposit and withdrawal history.
     * Net invested = sum of completed deposits - sum of completed withdrawals, converted to the user's currency.
     * Fetches history in 90-day windows going as far back as possible.
     */
    private function calculateInvestedAmount(Account $account, BinanceClient $client): ?int
    {
        $targetCurrency = strtoupper($account->user->currency_code);

        $deposits = $this->fetchAllDepositHistory($client);
        Sleep::for(self::THROTTLE_SECONDS)->seconds();
        $withdrawals = $this->fetchAllWithdrawHistory($client);

        if (empty($deposits) && empty($withdrawals)) {
            return null;
        }

        $totalDeposited = $this->sumTransactionAmounts($deposits, $targetCurrency, 'deposit');
        $totalWithdrawn = $this->sumTransactionAmounts($withdrawals, $targetCurrency, 'withdrawal');

        return (int) round(($totalDeposited - $totalWithdrawn) * 100);
    }

    /**
     * Fetch all deposit history by paginating through 90-day windows.
     * Goes back up to 2 years (8 windows of 90 days).
     *
     * @return array<int, array>
     */
    private function fetchAllDepositHistory(BinanceClient $client): array
    {
        return $this->fetchHistoryWindows(
            fn (int $start, int $end, int $offset) => $client->getDepositHistory($start, $end, $offset),
        );
    }

    /**
     * Fetch all withdrawal history by paginating through 90-day windows.
     * Goes back up to 2 years (8 windows of 90 days).
     *
     * @return array<int, array>
     */
    private function fetchAllWithdrawHistory(BinanceClient $client): array
    {
        return $this->fetchHistoryWindows(
            fn (int $start, int $end, int $offset) => $client->getWithdrawHistory($start, $end, $offset),
        );
    }

    /**
     * Generic method to fetch transaction history in 90-day windows going back up to 2 years.
     * Paginates within each window using offset when the API returns the maximum number of records.
     *
     * @param  callable(int, int, int): array  $fetcher
     * @return array<int, array>
     */
    private function fetchHistoryWindows(callable $fetcher): array
    {
        $allRecords = [];
        $endDate = now();
        $maxWindows = 8; // ~2 years of 90-day windows
        $limit = 1000;
        $isFirst = true;

        for ($i = 0; $i < $maxWindows; $i++) {
            $windowEnd = $endDate->copy()->subDays($i * self::DEPOSIT_WINDOW_DAYS);
            $windowStart = $windowEnd->copy()->subDays(self::DEPOSIT_WINDOW_DAYS);
            $offset = 0;

            do {
                if (! $isFirst) {
                    Sleep::for(self::THROTTLE_SECONDS)->seconds();
                }
                $isFirst = false;

                $records = $fetcher(
                    $windowStart->getTimestampMs(),
                    $windowEnd->getTimestampMs(),
                    $offset,
                );

                if (empty($records)) {
                    break;
                }

                foreach ($records as $record) {
                    $allRecords[] = $record;
                }

                $offset += count($records);
            } while (count($records) >= $limit);
        }

        return $allRecords;
    }

    /**
     * Sum transaction amounts, converting crypto amounts to fiat using the currency conversion service.
     * Only includes completed transactions (status=1 for deposits, status=6 for withdrawals)
     * and excludes internal transfers (transferType=1).
     *
     * @param  array<int, array>  $transactions
     */
    private function sumTransactionAmounts(array $transactions, string $targetCurrency, string $type): float
    {
        $successStatus = $type === 'deposit' ? 1 : 6;
        $total = 0.0;

        foreach ($transactions as $transaction) {
            $status = $transaction['status'] ?? -1;
            $transferType = $transaction['transferType'] ?? 0;
            $amount = (float) ($transaction['amount'] ?? 0);
            $coin = $transaction['coin'] ?? '';

            // Skip non-completed or internal transfers
            if ($status !== $successStatus || $transferType === 1 || $amount <= 0) {
                continue;
            }

            $date = $this->getTransactionDate($transaction, $type);

            // For stablecoins pegged to USD, convert directly
            if (in_array($coin, self::USD_STABLECOINS, true)) {
                $coin = 'USD';
            }

            if (strtoupper($coin) === $targetCurrency) {
                $total += $amount;
            } else {
                $converted = $this->currencyConverter->convert($coin, $targetCurrency, $amount, $date);
                $total += $converted;
            }
        }

        return $total;
    }

    /**
     * Extract the transaction date string from a deposit or withdrawal record.
     */
    private function getTransactionDate(array $transaction, string $type): string
    {
        if ($type === 'deposit') {
            // Deposit uses insertTime (timestamp in milliseconds)
            $timestamp = $transaction['insertTime'] ?? $transaction['completeTime'] ?? null;

            if ($timestamp) {
                return Carbon::createFromTimestampMs($timestamp)->toDateString();
            }
        } else {
            // Withdrawal uses completeTime or applyTime (string format)
            $completeTime = $transaction['completeTime'] ?? null;

            if ($completeTime) {
                return Carbon::parse($completeTime)->toDateString();
            }

            $applyTime = $transaction['applyTime'] ?? null;

            if ($applyTime) {
                return Carbon::parse($applyTime)->toDateString();
            }
        }

        return now()->toDateString();
    }
}
