<?php

namespace App\Services\Forecast;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BalanceLookup;
use Carbon\Carbon;

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
     * @return list<array{month: string, income: int, expenses: int, close: int, min: int}> Oldest first, today's month last.
     */
    public function forAccount(User $user, string $spaceId, Account $account, string $today, int $completeMonths = 2): array
    {
        $from = ForecastCalendar::monthStart($today, -$completeMonths);
        $flows = $this->flows($user, $spaceId, $account, $from, $today);
        $balances = BalanceLookup::forAccounts([$account->id], Carbon::parse($from), Carbon::parse($today));
        $months = [];

        for ($offset = -$completeMonths; $offset <= 0; $offset++) {
            $start = ForecastCalendar::monthStart($today, $offset);
            $end = min(ForecastCalendar::horizonEnd($start, 1), $today);
            $key = ForecastCalendar::monthKey($start);
            $months[] = [
                'month' => $key,
                'income' => $flows[$key]['income'] ?? 0,
                'expenses' => $flows[$key]['expenses'] ?? 0,
                ...$this->balanceRange($balances, $account->id, $start, $end),
            ];
        }

        return $months;
    }

    /**
     * @return array<string, array{income: int, expenses: int}> By `Y-m` month.
     */
    private function flows(User $user, string $spaceId, Account $account, string $from, string $to): array
    {
        $transactions = $this->history->transactions($user, $spaceId, $from, $to);
        $legs = $this->pairer->pair($transactions);
        $flows = [];

        foreach ($transactions as $transaction) {
            if ($transaction->account_id !== $account->id || isset($legs[$transaction->id])) {
                continue;
            }

            $kind = $this->kind($transaction);

            if ($kind !== null) {
                $month = ForecastCalendar::monthKey($transaction->transaction_date->toDateString());
                $share = $transaction->account->shareOfAmount($transaction->amount);
                $flows[$month][$kind] = ($flows[$month][$kind] ?? 0) + ($kind === 'income' ? $share : -$share);
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
     * @return array{close: int, min: int}
     */
    private function balanceRange(BalanceLookup $balances, string $accountId, string $start, string $end): array
    {
        $min = null;
        $close = 0;

        for ($day = Carbon::parse($start); $day->toDateString() <= $end; $day->addDay()) {
            $close = $balances->getBalanceAt($accountId, $day);
            $min = $min === null ? $close : min($min, $close);
        }

        return ['close' => $close, 'min' => $min ?? $close];
    }
}
