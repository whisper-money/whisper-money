<?php

namespace App\Services\Banking;

/**
 * Builds a deterministic fingerprint for an EnableBanking transaction
 * payload so we can dedup even when the upstream bank omits a stable
 * id (transaction_id / entry_reference), consumed by TransactionSyncService.
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

        $entryReference = $data['entry_reference'] ?? null;

        // Some ASPSPs emit a positional `{booking_date}.{index}` entry_reference
        // that is absent the day a transaction first appears and only populated
        // on a later sync. Keying on it fingerprints the same transaction
        // differently across syncs, so it slips past dedup and imports twice.
        // Treat that positional form as "no stable id" and fall through to the
        // content hash, which is identical on both syncs.
        //
        // Trade-off: the index is also the only field that would tell apart two
        // genuinely distinct same-day transactions with byte-identical content
        // (e.g. two identical tolls). Dropping it collapses them to one
        // fingerprint, so only the first is kept. We accept that here — a rare
        // silent under-count over the systematic duplication it fixes. Fixing
        // both needs occurrence-aware dedup in the consumer (a schema change),
        // tracked as a follow-up.
        if ($entryReference !== null && ! self::isPositionalReference($entryReference)) {
            return self::hash(['entry_reference', $entryReference]);
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

    private static function isPositionalReference(string $reference): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}\.\d+$/D', $reference) === 1;
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
