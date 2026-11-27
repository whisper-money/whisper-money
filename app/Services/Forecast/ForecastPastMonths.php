<?php

namespace App\Services\Forecast;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BalanceLookup;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * What really happened on one account in the months the forecast chart shows
 * before the projection: the complete months before today's and today's up to
 * today. Income and expenses leave transfers between the user's accounts out
 * (so both legs are read across every account), and use the owner's share;
 * `min` and `close` are the lowest and the last end-of-day balance.
 */
class ForecastPastMonths
{
    public function __construct(
        private readonly ForecastHistory $history,
        private readonly TransferPairer $pairer,
    ) {}

    /**
     * @return list<array{month: string, income: int, expenses: int, close: int|null, min: int|null}> Oldest first, today's month last.
     */
    public function forAccount(User $user, string $spaceId, Account $account, string $today, int $completeMonths = 2): array
    {
        return $this->forAccounts($user, $spaceId, new Collection([$account]), $today, $completeMonths)[$account->id];
    }

    /**
     * The same months for several accounts, read in one pass.
     *
     * @param  Collection<int, Account>  $accounts
     * @return array<string, list<array{month: string, income: int, expenses: int, close: int|null, min: int|null}>> By account id, each oldest first.
     */
    public function forAccounts(User $user, string $spaceId, Collection $accounts, string $today, int $completeMonths = 2): array
    {
        $from = ForecastCalendar::monthStart($today, -$completeMonths);
        $ids = $accounts->modelKeys();
        $flows = $this->flows($user, $spaceId, array_flip($ids), $from, $today);
        $balances = BalanceLookup::forAccounts($ids, Carbon::parse($from), Carbon::parse($today));
        $byAccount = [];

        foreach ($ids as $id) {
            for ($offset = -$completeMonths; $offset <= 0; $offset++) {
                $start = ForecastCalendar::monthStart($today, $offset);
                $end = min(ForecastCalendar::horizonEnd($start, 1), $today);
                $key = ForecastCalendar::monthKey($start);
                $byAccount[$id][] = [
                    'month' => $key,
                    'income' => $flows[$id][$key]['income'] ?? 0,
                    'expenses' => $flows[$id][$key]['expenses'] ?? 0,
                    ...$this->balanceRange($balances, $id, $start, $end),
                ];
            }
        }

        return $byAccount;
    }

    /**
     * @param  array<string, int>  $accountIds  The accounts read, as keys.
     * @return array<string, array<string, array{income?: int, expenses?: int}>> By account id, then `Y-m` month.
     */
    private function flows(User $user, string $spaceId, array $accountIds, string $from, string $to): array
    {
        $transactions = $this->history->transactions($user, $spaceId, $from, $to);
        $legs = $this->pairer->pair($transactions);
        $flows = [];

        foreach ($transactions as $transaction) {
            if (! isset($accountIds[$transaction->account_id]) || isset($legs[$transaction->id])) {
                continue;
            }

            $kind = $this->kind($transaction);

            if ($kind !== null) {
                $month = ForecastCalendar::monthKey($transaction->transaction_date->toDateString());
                $share = $transaction->account->shareOfAmount($transaction->amount);
                $flows[$transaction->account_id][$month][$kind] = ($flows[$transaction->account_id][$month][$kind] ?? 0) + ($kind === 'income' ? $share : -$share);
            }
        }

        return $flows;
    }

    /**
     * @return 'income'|'expenses'|null
     */
    private function kind(Transaction $transaction): ?string
    {
        if ($transaction->isIncomeSide()) {
            return 'income';
        }

        return $transaction->isExpenseSide() ? 'expenses' : null;
    }

    /**
     * The lowest and the last balance of the days anything is known about;
     * null for a month before the account's history starts.
     *
     * @return array{close: int|null, min: int|null}
     */
    private function balanceRange(BalanceLookup $balances, string $accountId, string $start, string $end): array
    {
        $min = null;
        $close = null;

        for ($day = Carbon::parse($start); $day->toDateString() <= $end; $day->addDay()) {
            if ($balances->hasHistoryOn($accountId, $day)) {
                $close = $balances->getBalanceAt($accountId, $day);
                $min = $min === null ? $close : min($min, $close);
            }
        }

        return ['close' => $close, 'min' => $min];
    }
}
