<?php

namespace App\Services\Banking;

use App\Models\Account;
use App\Models\BankingConnection;
use App\Services\CurrencyConversionService;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class KrakenBalanceSyncService
{
    private const USD_CURRENCY = 'USD';

    /** @var array<string, string> Kraken's legacy X/Z-prefixed codes (and ETH2) mapped onto the plain asset. */
    private const LEGACY_CODES = [
        'XXBT' => 'XBT',
        'XETH' => 'ETH',
        'ETH2' => 'ETH',
        'XXDG' => 'XDG',
        'XLTC' => 'LTC',
        'XXRP' => 'XRP',
        'XXLM' => 'XLM',
        'XETC' => 'ETC',
        'XZEC' => 'ZEC',
        'XXMR' => 'XMR',
        'XMLN' => 'MLN',
        'XREP' => 'REP',
        'ZEUR' => 'EUR',
        'ZUSD' => 'USD',
        'ZGBP' => 'GBP',
        'ZCAD' => 'CAD',
        'ZJPY' => 'JPY',
        'ZAUD' => 'AUD',
    ];

    /** @var array<string, string> Kraken asset codes the rate provider knows under another name. */
    private const CONVERTER_CODES = [
        'XBT' => 'BTC',
        'XDG' => 'DOGE',
    ];

    /** Ledger entry types that move money into or out of Kraken. */
    private const FUNDING_TYPES = ['deposit', 'withdrawal'];

    public function __construct(private CurrencyConversionService $currencyConverter) {}

    /**
     * Store today's portfolio value and net invested amount for the account.
     *
     * The invested amount is walked from the ledger once (first or forced full
     * sync, or no cursor yet), then kept up to date by adding only the entries
     * after `ledger_synced_until`.
     */
    public function sync(BankingConnection $connection, Account $account, KrakenClient $client, bool $isFirstSync): void
    {
        if (! $account->external_account_id) {
            return;
        }

        $currency = strtoupper($account->currency_code);
        $ticker = $client->getTickerPrices();
        $balance = $this->portfolioValue($client->getBalance(), $ticker, $currency);
        [$investedAmount, $cursor] = $this->investedAmount($connection, $account, $client, $ticker, $currency, $isFirstSync);

        DB::transaction(function () use ($connection, $account, $balance, $investedAmount, $cursor) {
            $account->balances()->updateOrCreate(
                ['balance_date' => now()->toDateString()],
                [
                    'balance' => $balance,
                    ...($investedAmount !== null ? ['invested_amount' => $investedAmount] : []),
                ],
            );

            $connection->update(['ledger_synced_until' => $cursor]);
        });
    }

    /**
     * Kraken's own asset code without the staking/earn suffix (DOT.S, XBT.M,
     * ETH2.S, USD.HOLD…) or the legacy prefix, so every variant of one asset
     * is priced as that asset.
     */
    private function normalizeAsset(string $asset): string
    {
        $base = strtoupper(explode('.', $asset)[0]);

        return self::LEGACY_CODES[$base] ?? $base;
    }

    /**
     * @param  array<string, string>  $balances
     * @param  array<string, float>  $ticker
     */
    private function portfolioValue(array $balances, array $ticker, string $currency): int
    {
        $total = 0.0;

        foreach ($balances as $asset => $quantity) {
            $quantity = (float) $quantity;

            if ($quantity <= 0) {
                continue;
            }

            $value = $this->valueToday($this->normalizeAsset($asset), $quantity, $ticker, $currency);

            if ($value === null) {
                Log::warning('Kraken asset could not be priced', ['asset' => $asset, 'target_currency' => $currency]);

                continue;
            }

            $total += $value;
        }

        return Money::toMinor($total, $currency);
    }

    /**
     * Net invested amount (deposits minus withdrawals, fiat and crypto) and the
     * cursor to store. Only `deposit`/`withdrawal` entries count: trades,
     * rewards and earn allocations have other types. Kraken's older staking
     * flow books a spot `withdrawal` against a `.S` `deposit` of the same asset;
     * both collapse onto one asset and are priced on one day, so they cancel.
     *
     * @param  array<string, float>  $ticker
     * @return array{0: int|null, 1: Carbon|null}
     */
    private function investedAmount(BankingConnection $connection, Account $account, KrakenClient $client, array $ticker, string $currency, bool $isFirstSync): array
    {
        $cursor = $isFirstSync ? null : $connection->ledger_synced_until;
        $previous = $cursor !== null ? $this->lastInvestedAmount($account) : null;

        if ($previous === null) {
            $cursor = null;
        }

        $entries = [];

        foreach (self::FUNDING_TYPES as $type) {
            $entries += $client->getLedgerEntries($type, $cursor?->getTimestamp());
        }

        if ($entries === []) {
            return [$previous, $cursor];
        }

        $delta = 0.0;
        $latest = $cursor?->getTimestamp() ?? 0;

        foreach ($entries as $entry) {
            $delta += $this->valueOnDate($entry, $ticker, $currency);
            $latest = max($latest, (int) ceil((float) $entry['time']));
        }

        return [($previous ?? 0) + Money::toMinor($delta, $currency), Carbon::createFromTimestamp($latest)];
    }

    /**
     * A ledger entry's signed amount (withdrawals are negative) in the target
     * currency on the day it happened, or at today's Kraken price when the rate
     * provider cannot price the asset on that day.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, float>  $ticker
     */
    private function valueOnDate(array $entry, array $ticker, string $currency): float
    {
        $asset = $this->normalizeAsset($entry['asset']);
        $amount = (float) $entry['amount'];
        $date = Carbon::createFromTimestamp((float) $entry['time'])->toDateString();

        $value = $this->currencyConverter->convert(self::CONVERTER_CODES[$asset] ?? $asset, $currency, $amount, $date);

        if ($value != 0.0 || $amount == 0.0) {
            return $value;
        }

        Log::info('Kraken ledger entry priced at today\'s price', ['asset' => $asset, 'date' => $date]);

        return $this->valueToday($asset, $amount, $ticker, $currency) ?? 0.0;
    }

    /**
     * Value a quantity of an asset in the target currency: Kraken's own pair
     * first, then through its USD pair, then the rate provider.
     *
     * @param  array<string, float>  $ticker
     */
    private function valueToday(string $asset, float $quantity, array $ticker, string $currency): ?float
    {
        if ($asset === $currency) {
            return $quantity;
        }

        $price = $this->tickerPrice($ticker, $asset, $currency);

        if ($price !== null) {
            return $quantity * $price;
        }

        $usdPrice = $asset === self::USD_CURRENCY ? 1.0 : $this->tickerPrice($ticker, $asset, self::USD_CURRENCY);
        $today = now()->toDateString();

        $value = $usdPrice !== null
            ? $this->currencyConverter->convert(self::USD_CURRENCY, $currency, $quantity * $usdPrice, $today)
            : $this->currencyConverter->convert(self::CONVERTER_CODES[$asset] ?? $asset, $currency, $quantity, $today);

        return $value != 0.0 ? $value : null;
    }

    /**
     * Kraken names pairs both ways: legacy `XXBTZEUR` and plain `DOTEUR`.
     *
     * @param  array<string, float>  $ticker
     */
    private function tickerPrice(array $ticker, string $asset, string $quote): ?float
    {
        foreach ([$asset, 'X'.$asset] as $base) {
            foreach ([$quote, 'Z'.$quote] as $quoteCode) {
                if (isset($ticker[$base.$quoteCode])) {
                    return $ticker[$base.$quoteCode];
                }
            }
        }

        return null;
    }

    private function lastInvestedAmount(Account $account): ?int
    {
        return $account->balances()
            ->whereNotNull('invested_amount')
            ->latest('balance_date')
            ->value('invested_amount');
    }
}
