<?php

namespace App\Services\Banking;

use App\Contracts\BankingProviderInterface;
use App\Enums\TransactionSource;
use App\Models\Account;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

class TransactionSyncService
{
    public function __construct(
        private BankingProviderInterface $provider,
        private TransactionDescriptionFormatter $descriptionFormatter,
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
        $bankName = $account->bank?->name;

        do {
            $result = $this->provider->getTransactions(
                $account->external_account_id,
                $dateFrom,
                $dateTo,
                $continuationKey,
                $strategy,
            );

            foreach ($result['transactions'] as $transaction) {
                if ($this->importTransaction($account, $transaction, $bankName)) {
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
     *
     * Dedup strategy: every transaction is keyed by a deterministic
     * fingerprint stored in `dedup_fingerprint` and protected by a
     * `(account_id, dedup_fingerprint)` unique index. The upstream
     * `transaction_id` / `entry_reference` is still preserved in
     * `external_transaction_id` when present, for traceability.
     *
     * This protects against:
     *  - Banks (e.g. BNP Paribas Fortis) that omit any stable id for
     *    certain card transactions, which previously bypassed dedup.
     *  - Race conditions between overlapping sync runs.
     */
    private function importTransaction(Account $account, array $data, ?string $bankName): bool
    {
        $externalId = $data['transaction_id'] ?? $data['entry_reference'] ?? null;
        $fingerprint = TransactionFingerprint::for($data);

        $exists = $account->transactions()
            ->withTrashed()
            ->where(function ($query) use ($fingerprint, $externalId) {
                $query->where('dedup_fingerprint', $fingerprint);

                if ($externalId !== null) {
                    // Also match legacy rows imported before the fingerprint
                    // column existed, which are keyed solely on the upstream id.
                    $query->orWhere('external_transaction_id', $externalId);
                }
            })
            ->exists();

        if ($exists) {
            return false;
        }

        $amount = $this->parseAmount($data);
        $rawDescription = $this->parseDescription($data);
        $formatted = $this->descriptionFormatter->format($rawDescription, $bankName);
        $counterparties = TransactionCounterpartyExtractor::fromPayload($data);
        $transactionDate = $this->parseDate($data);
        $currency = $data['transaction_amount']['currency'] ?? $account->currency_code;

        try {
            $account->transactions()->create([
                'user_id' => $account->user_id,
                'description' => $formatted['description'],
                'description_iv' => null,
                'original_description' => $formatted['original_description'],
                'transaction_date' => $transactionDate,
                'amount' => $amount,
                'currency_code' => $currency,
                'notes' => null,
                'notes_iv' => null,
                'source' => TransactionSource::EnableBanking,
                'external_transaction_id' => $externalId,
                'dedup_fingerprint' => $fingerprint,
                'raw_data' => $data,
                ...$counterparties,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Concurrent sync inserted the same fingerprint between our
            // exists() check and the insert. Treat as duplicate.
            return false;
        }

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
