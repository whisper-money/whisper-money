<?php

namespace App\Services\Banking;

use App\Contracts\BankingProviderInterface;
use App\Enums\TransactionSource;
use App\Models\Account;
use Illuminate\Support\Facades\Log;

class TransactionSyncService
{
    public function __construct(
        private BankingProviderInterface $provider,
    ) {}

    /**
     * Sync transactions for a connected account.
     *
     * @return int Number of new transactions created
     */
    public function sync(Account $account, string $dateFrom, string $dateTo, ?string $strategy = null, bool $saveDailyBalances = true): int
    {
        if (! $account->external_account_id) {
            return 0;
        }

        $created = 0;
        $continuationKey = null;
        $dailyBalances = [];

        do {
            $result = $this->provider->getTransactions(
                $account->external_account_id,
                $dateFrom,
                $dateTo,
                $continuationKey,
                $strategy,
            );

            foreach ($result['transactions'] as $transaction) {
                if ($this->importTransaction($account, $transaction)) {
                    $created++;
                }

                if ($saveDailyBalances) {
                    $this->trackDailyBalance($transaction, $dailyBalances);
                }
            }

            $continuationKey = $result['continuation_key'];
        } while ($continuationKey);

        if ($saveDailyBalances) {
            $this->saveDailyBalances($account, $dailyBalances);
        }

        Log::info('Synced transactions', [
            'account_id' => $account->id,
            'new_transactions' => $created,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);

        return $created;
    }

    /**
     * Import a single transaction, skipping duplicates.
     */
    private function importTransaction(Account $account, array $data): bool
    {
        $externalId = $data['transaction_id'] ?? $data['entry_reference'] ?? null;

        if ($externalId) {
            $exists = $account->transactions()
                ->withTrashed()
                ->where('external_transaction_id', $externalId)
                ->exists();

            if ($exists) {
                return false;
            }
        }

        $amount = $this->parseAmount($data);
        $description = $this->parseDescription($data);
        $transactionDate = $this->parseDate($data);
        $currency = $data['transaction_amount']['currency'] ?? $account->currency_code;

        $account->transactions()->create([
            'user_id' => $account->user_id,
            'description' => $description,
            'description_iv' => null,
            'transaction_date' => $transactionDate,
            'amount' => $amount,
            'currency_code' => $currency,
            'notes' => null,
            'notes_iv' => null,
            'source' => TransactionSource::EnableBanking,
            'external_transaction_id' => $externalId,
            'raw_data' => $data,
        ]);

        return true;
    }

    /**
     * Parse amount from EnableBanking transaction data.
     * Returns amount in cents (bigint). Debits are negative.
     */
    private function parseAmount(array $data): int
    {
        $rawAmount = $data['transaction_amount']['amount'] ?? '0';
        $cents = (int) round(floatval($rawAmount) * 100);

        $indicator = $data['credit_debit_indicator'] ?? null;

        if ($indicator === 'DBIT') {
            return -abs($cents);
        }

        return abs($cents);
    }

    /**
     * Parse description from EnableBanking transaction data.
     */
    private function parseDescription(array $data): string
    {
        $remittanceInfo = $data['remittance_information'] ?? [];

        if (! empty($remittanceInfo)) {
            return implode(' ', $remittanceInfo);
        }

        return $data['creditor']['name']
            ?? $data['debtor']['name']
            ?? 'Bank transaction';
    }

    /**
     * Parse transaction date, preferring booking_date.
     */
    private function parseDate(array $data): string
    {
        return $data['booking_date']
            ?? $data['transaction_date']
            ?? $data['value_date']
            ?? now()->toDateString();
    }

    /**
     * Track the balance after transaction for each day.
     * Overwrites so only the last transaction's balance per day is kept.
     *
     * @param  array<string, int>  $dailyBalances
     */
    private function trackDailyBalance(array $transaction, array &$dailyBalances): void
    {
        $balanceAfter = $transaction['balance_after_transaction'] ?? null;

        if (! $balanceAfter || ! isset($balanceAfter['amount'])) {
            return;
        }

        $date = $this->parseDate($transaction);
        $amount = (int) round(floatval($balanceAfter['amount']) * 100);

        $dailyBalances[$date] = $amount;
    }

    /**
     * Save tracked daily balances to the account.
     *
     * @param  array<string, int>  $dailyBalances
     */
    private function saveDailyBalances(Account $account, array $dailyBalances): void
    {
        foreach ($dailyBalances as $date => $balance) {
            $account->balances()->updateOrCreate(
                ['balance_date' => $date],
                ['balance' => $balance],
            );
        }
    }
}
