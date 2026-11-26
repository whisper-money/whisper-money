<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Models\User;
use App\Services\Forecast\ForecastAudience;
use App\Services\Forecast\ForecastMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pairs a batch of transactions with the forecast occurrences they paid: the
 * bulk counterpart of MatchTransactionToForecast, for the rows a full import
 * writes. Transactions that already have a pairing are left alone, so a
 * retry changes nothing twice, and owners without the `Forecast` flag or
 * without a forecast line are skipped.
 */
class MatchTransactionsToForecast implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    /**
     * @param  array<int, string>  $transactionIds
     */
    public function __construct(public array $transactionIds) {}

    public function handle(ForecastMatcher $matcher, ForecastAudience $audience): void
    {
        $owners = User::query()
            ->whereIn('id', Transaction::query()->whereIn('id', $this->transactionIds)->select('user_id'))
            ->get()
            ->filter(fn (User $user): bool => $audience->matchesTransactionsOf($user))
            ->modelKeys();

        if ($owners === []) {
            return;
        }

        Transaction::query()
            ->whereIn('id', $this->transactionIds)
            ->whereIn('user_id', $owners)
            ->chunkById(200, fn (Collection $transactions) => $matcher->matchUnmatched($transactions));
    }
}
