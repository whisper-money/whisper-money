<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class ManualBalanceAdjuster
{
    /**
     * The attributes whose edit moves what a transaction contributes to its
     * account's balance. The currency belongs here with the amount: the
     * snapshots are held in the account's own currency, so 2500 USD and
     * 2500 EUR shift them by different numbers.
     *
     * @var list<string>
     */
    public const BALANCE_AFFECTING_ATTRIBUTES = ['amount', 'transaction_date', 'account_id', 'currency_code'];

    public function __construct(private ExchangeRateService $exchangeRateService) {}

    /**
     * Reverse a deleted transaction's effect on its manual account's balances.
     *
     * Subtracts the transaction amount from its own day and every later
     * snapshot, mirroring the forward shift applied on creation. Connected
     * accounts are skipped because their balances come from bank sync.
     *
     * @return bool whether any balance was actually shifted
     */
    public function reverseDeletedTransaction(Transaction $transaction): bool
    {
        $account = $transaction->account;

        if ($account === null || $account->isConnected()) {
            return false;
        }

        $amount = $this->amountInAccountCurrency($transaction, $account);

        if ($amount === null) {
            return false;
        }

        $this->shiftBalancesFrom(
            $account,
            $transaction->transaction_date->toDateString(),
            -$amount,
        );

        return true;
    }

    /**
     * Apply a newly created transaction to its manual account's balances.
     *
     * Seeds a snapshot on the transaction's own date (from the carried-forward
     * balance when none exists yet), then shifts that day and every later
     * snapshot by the transaction amount. Connected accounts are skipped
     * because their balances come from bank sync.
     *
     * @return bool whether any balance was actually shifted
     */
    public function applyCreatedTransaction(Transaction $transaction): bool
    {
        $account = $transaction->account;

        if ($account === null || $account->isConnected()) {
            return false;
        }

        $amount = $this->amountInAccountCurrency($transaction, $account);

        if ($amount === null) {
            return false;
        }

        $transactionDate = $transaction->transaction_date->toDateString();

        $account->balances()->firstOrCreate(
            ['balance_date' => $transactionDate],
            ['balance' => $this->carriedForwardBalance($account, $transactionDate)],
        );

        $this->shiftBalancesFrom($account, $transactionDate, $amount);

        return true;
    }

    /**
     * The transaction amount as the account holds it, or null when that day
     * holds no rate to convert it with.
     *
     * Balance snapshots are kept in the account's own currency, so a
     * transaction in another one — the picker in the manual form, a mapped
     * currency column in an import — has to be converted before it shifts them.
     * Both directions read the same date's rate, so applying and reversing
     * cancel out exactly.
     *
     * With no rate the balance is left alone rather than shifted by the
     * unconverted number, which would be a wrong figure written into a money
     * column with nothing on screen to say so. The caller reports the balance
     * as untouched, and the warning is the trace worth having.
     */
    private function amountInAccountCurrency(Transaction $transaction, Account $account): ?int
    {
        $amount = $this->exchangeRateService->convertOrNull(
            $transaction->currency_code ?: $account->currency_code,
            $account->currency_code,
            $transaction->amount,
            $transaction->transaction_date->toDateString(),
        );

        if ($amount === null) {
            Log::warning('Balance left untouched: no rate to convert the transaction with', [
                'transaction_id' => $transaction->id,
                'account_id' => $account->id,
                'source' => $transaction->currency_code,
                'target' => $account->currency_code,
                'date' => $transaction->transaction_date->toDateString(),
            ]);
        }

        return $amount;
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
