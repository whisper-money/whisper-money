<?php

namespace App\Services;

use App\Models\AccountBalance;
use App\Models\Transaction;
use Illuminate\Support\Carbon;

class ManualBalanceAdjuster
{
    /**
     * Reverse a deleted transaction's effect on its manual account's current balance.
     *
     * Adjusts today's balance by the inverse of the transaction amount: an expense
     * (negative amount) increases the balance, income (positive amount) decreases it.
     * Connected accounts are skipped because their balances come from bank sync.
     */
    public function reverseDeletedTransaction(Transaction $transaction): void
    {
        $this->adjust($transaction, Carbon::now()->toDateString(), -$transaction->amount);
    }

    /**
     * Apply a newly created transaction to its manual account's balance.
     *
     * Adjusts the balance on the transaction's own date. The base is that day's
     * balance if one exists, otherwise the closest earlier balance, otherwise
     * zero (the first transaction on the account). Connected accounts are
     * skipped because their balances come from bank sync.
     */
    public function applyCreatedTransaction(Transaction $transaction): void
    {
        $this->adjust($transaction, $transaction->transaction_date->toDateString(), $transaction->amount);
    }

    /**
     * Reverse a transaction's effect on its manual account's balance on the
     * transaction's own date. Pair with applyCreatedTransaction to move the
     * balance when an existing manual transaction is edited.
     */
    public function reverseCreatedTransaction(Transaction $transaction): void
    {
        $this->adjust($transaction, $transaction->transaction_date->toDateString(), -$transaction->amount);
    }

    /**
     * Nudge the manual account's stored balance on a given date by a delta.
     * Connected accounts are skipped because their balances come from bank sync.
     */
    private function adjust(Transaction $transaction, string $balanceDate, int $delta): void
    {
        $account = $transaction->account;

        if ($account === null || $account->isConnected()) {
            return;
        }

        $baseBalance = $account->balances()
            ->where('balance_date', '<=', $balanceDate)
            ->orderByDesc('balance_date')
            ->value('balance') ?? 0;

        AccountBalance::updateOrCreate(
            [
                'account_id' => $account->id,
                'balance_date' => $balanceDate,
            ],
            [
                'balance' => $baseBalance + $delta,
            ],
        );
    }
}
