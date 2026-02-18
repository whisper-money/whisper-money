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
     * Fetches all crypto and fiat wallets and converts to the account's target currency.
     */
    public function sync(Account $account, BitpandaClient $client): void
    {
        if (! $account->external_account_id) {
            return;
        }

        $this->syncCurrentBalance($account, $client);
    }

    /**
     * Sync today's balance by fetching all wallets and converting to target currency.
     */
    public function syncCurrentBalance(Account $account, BitpandaClient $client): void
    {
        $targetCurrency = strtoupper($account->currency_code);
        $totalValue = 0.0;

        $totalValue += $this->sumCryptoWallets($client, $targetCurrency);
        $totalValue += $this->sumFiatWallets($client, $targetCurrency);

        $totalValueCents = (int) round($totalValue * 100);

        $account->balances()->updateOrCreate(
            ['balance_date' => now()->toDateString()],
            ['balance' => $totalValueCents],
        );
    }

    /**
     * Sum all crypto wallet balances, converting each to the target fiat currency.
     */
    private function sumCryptoWallets(BitpandaClient $client, string $targetCurrency): float
    {
        $wallets = $client->getCryptoWallets();
        $total = 0.0;

        foreach ($wallets['data'] ?? [] as $wallet) {
            $attributes = $wallet['attributes'] ?? [];
            $balance = (float) ($attributes['balance'] ?? 0);
            $symbol = $attributes['cryptocoin_symbol'] ?? null;
            $deleted = $attributes['deleted'] ?? false;

            if ($balance <= 0 || ! $symbol || $deleted) {
                continue;
            }

            $converted = $this->currencyConverter->convert($symbol, $targetCurrency, $balance);

            if ($converted == 0.0) {
                Log::warning('Could not convert Bitpanda asset to fiat', [
                    'asset' => $symbol,
                    'target_currency' => $targetCurrency,
                ]);

                continue;
            }

            $total += $converted;
        }

        return $total;
    }

    /**
     * Sum all fiat wallet balances, converting to the target currency if needed.
     */
    private function sumFiatWallets(BitpandaClient $client, string $targetCurrency): float
    {
        $wallets = $client->getFiatWallets();
        $total = 0.0;

        foreach ($wallets['data'] ?? [] as $wallet) {
            $attributes = $wallet['attributes'] ?? [];
            $balance = (float) ($attributes['balance'] ?? 0);
            $symbol = strtoupper($attributes['fiat_symbol'] ?? '');

            if ($balance <= 0 || ! $symbol) {
                continue;
            }

            if ($symbol === $targetCurrency) {
                $total += $balance;
            } else {
                $converted = $this->currencyConverter->convert($symbol, $targetCurrency, $balance);
                $total += $converted;
            }
        }

        return $total;
    }
}
