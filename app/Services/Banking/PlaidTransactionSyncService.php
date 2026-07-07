<?php

namespace App\Services\Banking;

use App\Enums\TransactionSource;
use App\Models\Account;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

class PlaidTransactionSyncService
{
    /**
     * Sync transactions for a Plaid account via /transactions/sync.
     *
     * The account's `external_account_id` is the Plaid `account_id`.
     *
     * @return int Number of new transactions created
     */
    public function sync(Account $account, PlaidClient $client, string $dateFrom, string $dateTo): int
    {
        if (! $account->external_account_id) {
            return 0;
        }

        $cursor = null;
        $created = 0;
        $hasMore = true;

        do {
            $result = $client->syncTransactions($cursor);
            $added = $result['added'] ?? [];
            $hasMore = $result['has_more'] ?? false;
            $cursor = $result['next_cursor'] ?? null;

            foreach ($added as $transaction) {
                if (($transaction['account_id'] ?? '') !== $account->external_account_id) {
                    continue;
                }

                $parsed = $this->parseTransaction($transaction);

                if ($parsed === null) {
                    continue;
                }

                if ($this->importTransaction($account, $transaction, $parsed)) {
                    $created++;
                }
            }
        } while ($hasMore);

        Log::info('Synced Plaid transactions', [
            'account_id' => $account->id,
            'external_account_id' => $account->external_account_id,
            'new_transactions' => $created,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);

        return $created;
    }

    /**
     * Parse a Plaid transaction into normalized amount + description.
     *
     * @return array{amount_cents: int, currency: string, description: string}|null
     */
    private function parseTransaction(array $transaction): ?array
    {
        $amount = (float) ($transaction['amount'] ?? 0);

        if ($amount === 0.0) {
            return null;
        }

        $amountCents = (int) round($amount * 100);

        $currency = $transaction['iso_currency_code'] ?? 'USD';

        // Prefer merchant_name, fall back to name
        $description = $transaction['merchant_name']
            ?? $transaction['name']
            ?? 'Plaid Transaction';

        return [
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'description' => $description,
        ];
    }

    private function importTransaction(Account $account, array $transaction, array $parsed): bool
    {
        $externalId = $transaction['transaction_id'] ?? null;
        $fingerprint = $this->fingerprint($transaction, $parsed);

        $exists = $account->transactions()
            ->withTrashed()
            ->where(function ($query) use ($fingerprint, $externalId) {
                $query->where('dedup_fingerprint', $fingerprint);

                if ($externalId !== null) {
                    $query->orWhere('external_transaction_id', $externalId);
                }
            })
            ->exists();

        if ($exists) {
            return false;
        }

        $transactionDate = $transaction['date'] ?? now()->toDateString();

        try {
            $account->transactions()->create([
                'user_id' => $account->user_id,
                'description' => $parsed['description'],
                'description_iv' => null,
                'original_description' => $parsed['description'],
                'transaction_date' => $transactionDate,
                'amount' => $parsed['amount_cents'],
                'currency_code' => $parsed['currency'],
                'notes' => null,
                'notes_iv' => null,
                'source' => TransactionSource::Plaid,
                'external_transaction_id' => $externalId,
                'dedup_fingerprint' => $fingerprint,
                'raw_data' => $transaction,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    private function fingerprint(array $transaction, array $parsed): string
    {
        $id = $transaction['transaction_id'] ?? null;

        if ($id !== null) {
            return 'fp_'.hash('sha256', implode("\x1f", ['plaid_transaction_id', $id]));
        }

        return 'fp_'.hash('sha256', implode("\x1f", [
            $transaction['date'] ?? '',
            (string) $parsed['amount_cents'],
            $parsed['currency'],
            $parsed['description'],
        ]));
    }
}
