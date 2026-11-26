<?php

namespace App\Listeners;

use App\Events\TransactionCreated;
use App\Events\TransactionUpdated;
use App\Services\Forecast\ForecastAudience;
use App\Services\Forecast\ForecastMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Pairs a new or edited transaction with the forecast occurrence it paid.
 *
 * Nothing is queued for a user without the `Forecast` flag or without a
 * forecast line, for an edit that changed nothing a match depends on, or for
 * a row a full import writes (the import matches its rows in bulk, see
 * MatchTransactionsToForecast).
 *
 * Known limit: a change no column of the transaction records (its labels, or
 * the name of its account, bank or category) is not seen here. An unmatched
 * transaction is picked up by the hourly `forecast:refresh`; an existing
 * automatic pairing stays until the transaction is next edited.
 */
class MatchTransactionToForecast implements ShouldQueue
{
    /**
     * What a match reads: the amount, date and account, and the fields a
     * recognition rule can test.
     */
    private const MATCHED_ON = [
        'amount',
        'transaction_date',
        'account_id',
        'description',
        'category_id',
        'notes',
        'creditor_name',
        'debtor_name',
    ];

    public function __construct(
        private readonly ForecastMatcher $matcher,
        private readonly ForecastAudience $audience,
    ) {}

    public function shouldQueue(TransactionCreated|TransactionUpdated $event): bool
    {
        $transaction = $event->transaction;

        if ($event instanceof TransactionCreated && $transaction->import_id !== null) {
            return false;
        }

        if ($event instanceof TransactionUpdated && ! $transaction->wasChanged(self::MATCHED_ON)) {
            return false;
        }

        return $this->audience->matchesTransaction($transaction);
    }

    public function handle(TransactionCreated|TransactionUpdated $event): void
    {
        $transaction = $event->transaction;

        // Checked again: the flag or the lines may have changed while queued.
        if (! $this->audience->matchesTransaction($transaction)) {
            return;
        }

        $this->matcher->match($transaction);
    }
}
