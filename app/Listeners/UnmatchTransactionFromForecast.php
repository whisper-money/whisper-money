<?php

namespace App\Listeners;

use App\Events\TransactionDeleted;
use App\Services\Forecast\ForecastMatcher;

/**
 * A deleted transaction no longer pays its forecast occurrence, which goes
 * back to pending.
 *
 * Run in line, not queued: it is a single delete by transaction id, a no-op
 * for anyone who never had a match (every user without the `Forecast` flag),
 * and it creates nothing. It runs regardless of the flag so that a match
 * never outlives its transaction.
 */
class UnmatchTransactionFromForecast
{
    public function __construct(private readonly ForecastMatcher $matcher) {}

    public function handle(TransactionDeleted $event): void
    {
        $this->matcher->unmatch($event->transaction->id);
    }
}
