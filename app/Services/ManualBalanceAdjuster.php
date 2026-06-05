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
}
