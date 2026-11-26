<?php

namespace App\Services\Forecast\Refresh;

use App\Models\ForecastItem;
use App\Models\ForecastMatch;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Forecast\ForecastAudience;
use App\Services\Forecast\ForecastMatcher;

/**
 * Tries again to pair the user's recent transactions that pay no forecast
 * occurrence yet: a line created or edited after a payment arrived, or an
 * occurrence freed by a deleted or edited transaction, picks its payment up
 * here.
 */
class RematchRecentTransactions implements ForecastRefreshStep
{
    private const LOOKBACK_DAYS = 45;

    public function __construct(
        private readonly ForecastMatcher $matcher,
        private readonly ForecastAudience $audience,
    ) {}

    public function run(User $user): void
    {
        if (! $this->audience->matchesTransactionsOf($user)) {
            return;
        }

        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->whereIn('account_id', $this->accountsWithLines($user))
            ->transactionDateWithin($user->localToday()->subDays(self::LOOKBACK_DAYS)->toDateString(), null)
            ->whereNotIn('id', ForecastMatch::query()->select('transaction_id'))
            ->lazyById();

        $this->matcher->matchUnmatched($transactions);
    }

    /**
     * @return list<string>
     */
    private function accountsWithLines(User $user): array
    {
        return ForecastItem::query()
            ->where('user_id', $user->id)
            ->notArchived()
            ->get(['account_id', 'destination_account_id'])
            ->flatMap(fn (ForecastItem $item): array => [$item->account_id, $item->destination_account_id])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
