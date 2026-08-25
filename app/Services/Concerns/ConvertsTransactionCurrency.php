<?php

namespace App\Services\Concerns;

use App\Models\Transaction;
use App\Services\ExchangeRateService;
use Illuminate\Support\Collection;

/**
 * Shared currency conversion for whoever adds up transactions. Each consumer
 * injects an {@see ExchangeRateService} as `$exchangeRateService`, then reads
 * transaction amounts in the user's currency through these helpers.
 */
trait ConvertsTransactionCurrency
{
    /**
     * The transaction amount in the user's currency, reduced to their share of
     * the account. Requires the `account` relation to be eager loaded.
     */
    protected function convertTransactionAmount(Transaction $transaction, string $currency): int
    {
        $converted = $this->exchangeRateService->convert(
            $transaction->currency_code ?: $transaction->account?->currency_code ?: $currency,
            $currency,
            $transaction->amount,
            $transaction->transaction_date->toDateString(),
        );

        return $transaction->ownerShareOf($converted);
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    protected function preloadExchangeRates(Collection $transactions, string $currency): void
    {
        $dates = $transactions
            ->filter(fn (Transaction $transaction): bool => strcasecmp($transaction->currency_code ?: $transaction->account?->currency_code ?: $currency, $currency) !== 0)
            ->map(fn (Transaction $transaction): string => $transaction->transaction_date->toDateString())
            ->unique()
            ->values();

        if ($dates->isEmpty()) {
            return;
        }

        $this->exchangeRateService->preloadRates($currency, $dates);
    }
}
