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

        // Preload the account's existing dedup keys once. Without this every
        // incoming transaction ran its own exists() probe (the N+1 in
        // PHP-LARAVEL-3Y). Keys inserted during this run are folded back into
        // the sets so duplicates within the same sync are still caught in
        // memory, and the unique index still backstops concurrent syncs.
        // ponytail: loads every key for the account; if one account's history
        // ever dwarfs its sync window, narrow this to the incoming batch's keys.
        [$knownFingerprints, $knownExternalIds] = $this->loadExistingDedupKeys($account);

        do {
            $result = $this->provider->getTransactions(
                $account->external_account_id,
                $dateFrom,
                $dateTo,
                $continuationKey,
                $strategy,
            );

            foreach ($result['transactions'] as $transaction) {
                if ($this->importTransaction($account, $transaction, $bankName, $knownFingerprints, $knownExternalIds)) {
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
    private function importTransaction(Account $account, array $data, ?string $bankName, array &$knownFingerprints, array &$knownExternalIds): bool
    {
        $externalId = $data['transaction_id'] ?? $data['entry_reference'] ?? null;
        $fingerprint = TransactionFingerprint::for($data);

        // Mirror of the previous exists() probe against the preloaded sets:
        // match on the fingerprint, or — for legacy rows keyed solely on the
        // upstream id before the fingerprint column existed — the external id.
        $exists = isset($knownFingerprints[$fingerprint])
            || ($externalId !== null && isset($knownExternalIds[$this->dedupExternalIdKey($externalId)]));

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

        $knownFingerprints[$fingerprint] = true;

        if ($externalId !== null) {
            $knownExternalIds[$this->dedupExternalIdKey($externalId)] = true;
        }

        return true;
    }

    /**
     * Normalize an external transaction id for dedup lookups so matching stays
     * case-insensitive, mirroring the production `utf8mb4_unicode_ci` collation
     * the old `where external_transaction_id = ?` probe relied on. Without this
     * a legacy id stored as `ABC` would no longer dedup an incoming `abc`, and
     * since there is no unique index on `external_transaction_id` that would
     * silently double-import the transaction. (Accent/width folding is not
     * replicated; bank reference ids are ASCII in practice.)
     */
    private function dedupExternalIdKey(string $externalId): string
    {
        return mb_strtolower($externalId);
    }

    /**
     * Preload the account's existing dedup keys, including soft-deleted rows,
     * so duplicate detection runs against in-memory sets instead of one
     * exists() query per incoming transaction.
     *
     * @return array{0: array<string, true>, 1: array<string, true>} fingerprints keyed set, external ids keyed set
     */
    private function loadExistingDedupKeys(Account $account): array
    {
        $knownFingerprints = [];
        $knownExternalIds = [];

        // cursor() streams rows so peak memory is the two sets, not an extra
        // buffered Collection of every historical row on top of them.
        $rows = $account->transactions()
            ->withTrashed()
            ->toBase()
            ->select(['dedup_fingerprint', 'external_transaction_id'])
            ->cursor();

        foreach ($rows as $row) {
            if ($row->dedup_fingerprint !== null) {
                $knownFingerprints[$row->dedup_fingerprint] = true;
            }

            if ($row->external_transaction_id !== null) {
                $knownExternalIds[$this->dedupExternalIdKey($row->external_transaction_id)] = true;
            }
        }

        return [$knownFingerprints, $knownExternalIds];
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
