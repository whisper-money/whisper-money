<?php

namespace App\Services\Banking;

use App\Models\Account;
use App\Services\CurrencyConversionService;
use Illuminate\Support\Facades\Log;

class BitpandaBalanceSyncService
{
    public function __construct(private CurrencyConversionService $currencyConverter) {}

    /**
     * Sync the total portfolio value for a Bitpanda account.
     * Uses Bitpanda's own ticker prices to match the values shown in the Bitpanda dashboard.
     * Also calculates the invested amount from fiat deposit/withdrawal history.
     */
    public function sync(Account $account, BitpandaClient $client): void
    {
        if (! $account->external_account_id) {
            return;
        }

        $investedAmountCents = $this->calculateInvestedAmount($client, strtoupper($account->user->currency_code));
        $this->syncCurrentBalance($account, $client, $investedAmountCents);
    }

    /**
     * Sync today's balance by fetching all wallets and converting to target currency
     * using Bitpanda's own ticker prices.
     */
    public function syncCurrentBalance(Account $account, BitpandaClient $client, ?int $investedAmountCents = null): void
    {
        $targetCurrency = strtoupper($account->currency_code);
        $ticker = $client->getTickerPrices();
        $totalValue = 0.0;

        $totalValue += $this->sumCryptoWallets($client, $ticker, $targetCurrency);
        $totalValue += $this->sumFiatWallets($client, $targetCurrency);

        $totalValueCents = (int) round($totalValue * 100);

        $account->balances()->updateOrCreate(
            ['balance_date' => now()->toDateString()],
            [
                'balance' => $totalValueCents,
                ...($investedAmountCents !== null ? ['invested_amount' => $investedAmountCents] : []),
            ],
        );
    }

    /**
     * Sum all crypto wallet balances using Bitpanda's ticker prices.
     *
     * @param  array<string, array<string, string>>  $ticker
     */
    private function sumCryptoWallets(BitpandaClient $client, array $ticker, string $targetCurrency): float
    {
        $wallets = $client->getCryptoWallets();
        $total = 0.0;

        foreach ($wallets['data'] as $wallet) {
            $attributes = $wallet['attributes'];
            $balance = (float) $attributes['balance'];
            $symbol = $attributes['cryptocoin_symbol'];
            $deleted = $attributes['deleted'];

            if ($balance <= 0 || ! $symbol || $deleted) {
                continue;
            }

            $price = $this->getTickerPrice($ticker, $symbol, $targetCurrency);

            if ($price === null) {
                Log::warning('Bitpanda ticker price not found for asset', [
                    'asset' => $symbol,
                    'target_currency' => $targetCurrency,
                ]);

                continue;
            }

            $total += $balance * $price;
        }

        return $total;
    }

    /**
     * Sum all fiat wallet balances, using ticker to convert if needed.
     */
    private function sumFiatWallets(BitpandaClient $client, string $targetCurrency): float
    {
        $wallets = $client->getFiatWallets();
        $total = 0.0;

        foreach ($wallets['data'] as $wallet) {
            $attributes = $wallet['attributes'];
            $balance = (float) $attributes['balance'];
            $symbol = strtoupper($attributes['fiat_symbol']);

            if ($balance <= 0 || ! $symbol) {
                continue;
            }

            if ($symbol === $targetCurrency) {
                $total += $balance;
            } else {
                $total += $this->currencyConverter->convert(
                    $symbol,
                    $targetCurrency,
                    $balance,
                    now()->toDateString(),
                );
            }
        }

        return $total;
    }

    /**
     * Get the ticker price for an asset in the target currency.
     *
     * @param  array<string, array<string, string>>  $ticker
     */
    private function getTickerPrice(array $ticker, string $symbol, string $targetCurrency): ?float
    {
        $price = $ticker[$symbol][$targetCurrency] ?? null;

        if ($price === null) {
            return null;
        }

        return (float) $price;
    }

    /**
     * Calculate net invested amount from fiat deposit and withdrawal history.
     * Net invested = total deposits - total withdrawals (in cents).
     */
    private function calculateInvestedAmount(BitpandaClient $client, string $targetCurrency): ?int
    {
        $deposits = $client->getAllFiatTransactions('deposit');
        $withdrawals = $client->getAllFiatTransactions('withdrawal');

        $totalDeposited = $this->sumFiatTransactions($deposits, $targetCurrency);
        $totalWithdrawn = $this->sumFiatTransactions($withdrawals, $targetCurrency);

        if ($totalDeposited === 0.0 && $totalWithdrawn === 0.0) {
            return null;
        }

        return (int) round(($totalDeposited - $totalWithdrawn) * 100);
    }

    /**
     * Sum the amounts of fiat transactions in the target currency.
     * Only considers finished transactions whose fiat_id matches the target currency.
     *
     * @param  array<int, array{type: string, id: string, attributes: array}>  $transactions
     */
    private function sumFiatTransactions(array $transactions, string $targetCurrency): float
    {
        $total = 0.0;

        foreach ($transactions as $transaction) {
            $attributes = $transaction['attributes'];
            $status = $attributes['status'] ?? '';
            $amount = (float) ($attributes['amount'] ?? 0);

            if ($status !== 'finished' || $amount <= 0) {
                continue;
            }

            $fiatId = strtoupper($attributes['fiat_id'] ?? '');

            if ($fiatId !== $targetCurrency) {
                $total += $this->currencyConverter->convert(
                    $fiatId,
                    $targetCurrency,
                    $amount,
                    now()->toDateString(),
                );

                continue;
            }

            $total += $amount;
        }

        return $total;
    }
}
