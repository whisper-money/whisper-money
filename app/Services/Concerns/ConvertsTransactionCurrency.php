<?php

namespace App\Services\Concerns;

use App\Models\Account;
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
        return $transaction->ownerShareOf($this->convertFullTransactionAmount($transaction, $currency));
    }

    /**
     * The whole transaction amount in $currency, ignoring the owner's share of
     * the account. Requires the `account` relation to be eager loaded.
     */
    protected function convertFullTransactionAmount(Transaction $transaction, string $currency): int
    {
        return $this->exchangeRateService->convert(
            $this->sourceCurrency($transaction->currency_code, $transaction->account, $currency),
            $currency,
            $transaction->amount,
            $transaction->transaction_date->toDateString(),
        );
    }

    /**
     * The currency an amount is booked in: the transaction's own, else its
     * account's, else the user's.
     */
    protected function sourceCurrency(?string $transactionCurrency, ?Account $account, string $currency): string
    {
        return $transactionCurrency ?: $account?->currency_code ?: $currency;
    }

    /**
     * The signed total of a set of transactions in the user's currency.
     *
     * @param  Collection<int, Transaction>  $transactions
     */
    protected function sumConvertedAmounts(Collection $transactions, string $currency): int
    {
        return $transactions->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $currency));
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    protected function preloadExchangeRates(Collection $transactions, string $currency): void
    {
        $dates = $transactions
            ->filter(fn (Transaction $transaction): bool => strcasecmp($this->sourceCurrency($transaction->currency_code, $transaction->account, $currency), $currency) !== 0)
            ->map(fn (Transaction $transaction): string => $transaction->transaction_date->toDateString())
            ->unique()
            ->values();

        if ($dates->isEmpty()) {
            return;
        }

        $this->exchangeRateService->preloadRates($currency, $dates);
    }
}
