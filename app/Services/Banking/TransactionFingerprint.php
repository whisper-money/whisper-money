<?php

namespace App\Services\Banking;

/**
 * Builds a deterministic fingerprint for an EnableBanking transaction
 * payload so we can dedup even when the upstream bank omits a stable
 * id (transaction_id / entry_reference).
 *
 * Shared between the live sync path and the cleanup command so they
 * stay in lock-step.
 */
class TransactionFingerprint
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function for(array $data): string
    {
        if (($data['transaction_id'] ?? null) !== null) {
            return self::hash(['transaction_id', $data['transaction_id']]);
        }

        if (($data['entry_reference'] ?? null) !== null) {
            return self::hash(['entry_reference', $data['entry_reference']]);
        }

        return self::hash([
            $data['booking_date'] ?? '',
            $data['transaction_amount']['amount'] ?? '',
            $data['transaction_amount']['currency'] ?? '',
            $data['credit_debit_indicator'] ?? '',
            $data['creditor']['name'] ?? '',
            $data['debtor']['name'] ?? '',
            $data['creditor_account']['iban'] ?? '',
            $data['debtor_account']['iban'] ?? '',
            $data['debtor_account']['other']['identification'] ?? '',
            $data['creditor_account']['other']['identification'] ?? '',
            $data['bank_transaction_code']['code'] ?? '',
            $data['bank_transaction_code']['sub_code'] ?? '',
            $data['reference_number'] ?? '',
            self::remittance($data['remittance_information'] ?? []),
        ]);
    }

    /**
     * @param  array<int, string>|string  $remittance
     */
    private static function remittance(array|string $remittance): string
    {
        if (is_string($remittance)) {
            return $remittance;
        }

        return implode('|', $remittance);
    }

    /**
     * @param  array<int, mixed>  $parts
     */
    private static function hash(array $parts): string
    {
        return 'fp_'.hash('sha256', implode("\x1f", array_map('strval', $parts)));
    }
}
