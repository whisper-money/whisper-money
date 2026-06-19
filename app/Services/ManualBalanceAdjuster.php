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
        $account = $transaction->account;

        if ($account === null || $account->isConnected()) {
            return;
        }

        $today = Carbon::now()->toDateString();

        $currentBalance = $account->balances()
            ->where('balance_date', '<=', $today)
            ->orderByDesc('balance_date')
            ->value('balance') ?? 0;

        AccountBalance::updateOrCreate(
            [
                'account_id' => $account->id,
                'balance_date' => $today,
            ],
            [
                'balance' => $currentBalance - $transaction->amount,
            ],
        );
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
        $account = $transaction->account;

        if ($account === null || $account->isConnected()) {
            return;
        }

        $transactionDate = $transaction->transaction_date->toDateString();

        $baseBalance = $account->balances()
            ->where('balance_date', '<=', $transactionDate)
            ->orderByDesc('balance_date')
            ->value('balance') ?? 0;

        AccountBalance::updateOrCreate(
            [
                'account_id' => $account->id,
                'balance_date' => $transactionDate,
            ],
            [
                'balance' => $baseBalance + $transaction->amount,
            ],
        );
    }
}
