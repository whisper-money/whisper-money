<?php

namespace App\Services\Banking;

use App\Enums\TransactionSource;
use App\Models\Account;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

class WiseTransactionSyncService
{
    /**
     * Sync transactions for a Wise currency wallet via the activities API.
     *
     * The account's `external_account_id` must be in the format
     * "{profileId}:{currency}" (e.g. "36875276:EUR").
     *
     * @return int Number of new transactions created
     */
    public function sync(Account $account, WiseClient $client, string $dateFrom, string $dateTo): int
    {
        if (! $account->external_account_id) {
            return 0;
        }

        [$profileId, $currency] = explode(':', $account->external_account_id, 2);

        $since = $dateFrom.'T00:00:00Z';
        $until = $dateTo.'T23:59:59Z';
        $cursor = null;
        $created = 0;

        do {
            $result = $client->getActivities((int) $profileId, $since, $until, $cursor);
            $activities = $result['activities'] ?? [];
            $cursor = $result['cursor'] ?? null;

            foreach ($activities as $activity) {
                // Skip zero-amount authorization checks and non-monetary types
                if (($activity['type'] ?? '') === 'CARD_CHECK') {
                    continue;
                }

                $parsed = $this->parseActivity($activity, $currency);

                if ($parsed === null) {
                    continue;
                }

                if ($this->importTransaction($account, $activity, $parsed)) {
                    $created++;
                }
            }
        } while ($cursor !== null && count($activities) > 0);

        Log::info('Synced Wise transactions', [
            'account_id' => $account->id,
            'currency' => $currency,
            'new_transactions' => $created,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);

        return $created;
    }

    /**
     * Parse a Wise activity into a normalised amount + currency.
     * Returns null if the activity does not involve the target currency wallet.
     *
     * @return array{amount_cents: int, currency: string, description: string}|null
     */
    private function parseActivity(array $activity, string $walletCurrency): ?array
    {
        $primary = $activity['primaryAmount'] ?? '';
        $secondary = $activity['secondaryAmount'] ?? '';

        // Determine sign from HTML tags in the amount string
        $isPositive = str_contains($primary, '<positive>');

        // Strip HTML tags and whitespace to get a clean "1,234.56 EUR" string
        $primaryClean = trim(strip_tags($primary));
        $secondaryClean = trim(strip_tags($secondary));

        // Parse "1,234.56 EUR" → [float value, string currency]
        $primaryParsed = $this->parseAmountString($primaryClean);
        $secondaryParsed = $this->parseAmountString($secondaryClean);

        $type = $activity['type'] ?? '';

        // Determine which value to record and whether it's wallet-relevant
        if ($primaryParsed !== null && $primaryParsed[1] === $walletCurrency) {
            // Direct wallet-currency transaction
            $value = $primaryParsed[0];
            $recordCurrency = $walletCurrency;
        } elseif ($secondaryParsed !== null && $secondaryParsed[1] === $walletCurrency) {
            // Foreign-currency spend, EUR equivalent shown in secondary
            $value = $secondaryParsed[0];
            $recordCurrency = $walletCurrency;
        } else {
            // Transaction doesn't touch this wallet
            return null;
        }

        // Determine sign: card payments are always debits; transfers/conversions use HTML tag
        $sign = match (true) {
            in_array($type, ['CARD_PAYMENT', 'CARD_CASHBACK_REVERSAL']) => -1,
            $isPositive => 1,
            default => -1,
        };

        $amountCents = (int) round($value * 100) * $sign;

        $description = trim(strip_tags($activity['title'] ?? ''));

        return [
            'amount_cents' => $amountCents,
            'currency' => $recordCurrency,
            'description' => $description,
        ];
    }

    /**
     * Parse a string like "1,234.56 EUR" or "+ 500 EUR" into [float, currency].
     *
     * @return array{float, string}|null
     */
    private function parseAmountString(string $str): ?array
    {
        // Remove sign prefix, commas (thousands separator), extra spaces
        $str = trim(preg_replace('/[+\-,]/', '', $str) ?? $str);

        // Match number and 3-letter currency code, e.g. "1234.56 EUR"
        if (! preg_match('/^([\d.]+)\s+([A-Z]{3})$/', $str, $m)) {
            return null;
        }

        return [(float) $m[1], $m[2]];
    }

    /**
     * @param  array{amount_cents: int, currency: string, description: string}  $parsed
     */
    private function importTransaction(Account $account, array $activity, array $parsed): bool
    {
        $externalId = $activity['id'] ?? null;
        $fingerprint = $this->fingerprint($activity, $parsed);

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

        $transactionDate = substr($activity['createdOn'] ?? now()->toIso8601String(), 0, 10);

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
                'source' => TransactionSource::Wise,
                'external_transaction_id' => $externalId,
                'dedup_fingerprint' => $fingerprint,
                'raw_data' => $activity,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    private function fingerprint(array $activity, array $parsed): string
    {
        $id = $activity['id'] ?? null;

        if ($id !== null) {
            return 'fp_'.hash('sha256', implode("\x1f", ['wise_activity_id', $id]));
        }

        return 'fp_'.hash('sha256', implode("\x1f", [
            substr($activity['createdOn'] ?? '', 0, 10),
            (string) $parsed['amount_cents'],
            $parsed['currency'],
            $parsed['description'],
        ]));
    }
}
