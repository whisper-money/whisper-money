<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Transaction;
use App\Services\ExchangeRateService;
use Illuminate\Support\Collection;

/**
 * Shared currency conversion for the analytics controllers. Each consumer
 * injects an {@see ExchangeRateService} as `$exchangeRateService`, then reads
 * transaction amounts in the user's currency through these helpers.
 */
trait ConvertsTransactionCurrency
{
    protected function convertTransactionAmount(Transaction $transaction, string $currency): int
    {
        return $this->exchangeRateService->convert(
            $transaction->currency_code ?: $transaction->account?->currency_code ?: $currency,
            $currency,
            $transaction->amount,
            $transaction->transaction_date->toDateString(),
        );
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
