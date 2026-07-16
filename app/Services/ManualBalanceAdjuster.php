<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;

class ManualBalanceAdjuster
{
    /**
     * Reverse a deleted transaction's effect on its manual account's balances.
     *
     * Subtracts the transaction amount from its own day and every later
     * snapshot, mirroring the forward shift applied on creation. Connected
     * accounts are skipped because their balances come from bank sync.
     */
    public function reverseDeletedTransaction(Transaction $transaction): void
    {
        $account = $transaction->account;

        if ($account === null || $account->isConnected()) {
            return;
        }

        $this->shiftBalancesFrom(
            $account,
            $transaction->transaction_date->toDateString(),
            -$transaction->amount,
        );
    }

    /**
     * Apply a newly created transaction to its manual account's balances.
     *
     * Seeds a snapshot on the transaction's own date (from the carried-forward
     * balance when none exists yet), then shifts that day and every later
     * snapshot by the transaction amount. Connected accounts are skipped
     * because their balances come from bank sync.
     */
    public function applyCreatedTransaction(Transaction $transaction): void
    {
        $account = $transaction->account;

        if ($account === null || $account->isConnected()) {
            return;
        }

        $transactionDate = $transaction->transaction_date->toDateString();

        $account->balances()->firstOrCreate(
            ['balance_date' => $transactionDate],
            ['balance' => $this->carriedForwardBalance($account, $transactionDate)],
        );

        $this->shiftBalancesFrom($account, $transactionDate, $transaction->amount);
    }

    /**
     * Shift every balance snapshot on or after the given date by the delta.
     *
     * Balances carry forward, so a retroactive change must move the
     * transaction's own day and every later snapshot (such as today's current
     * balance) by the same amount to keep the running balance consistent.
     */
    private function shiftBalancesFrom(Account $account, string $fromDate, int $delta): void
    {
        $account->balances()
            ->where('balance_date', '>=', $fromDate)
            ->increment('balance', $delta);
    }

    /**
     * The most recent balance strictly before the given date, or 0 if none.
     */
    private function carriedForwardBalance(Account $account, string $date): int
    {
        return $account->balances()
            ->where('balance_date', '<', $date)
            ->orderByDesc('balance_date')
            ->value('balance') ?? 0;
    }
}
