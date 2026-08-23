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
     * Banks whose upstream id cannot identify a transaction, because they mint
     * a new one every time they hand the same transaction over.
     *
     * N26 never sends `transaction_id` (null on all 7916 production rows) and
     * has changed `entry_reference` three times in one month: v1 UUIDs minted
     * per delivery until 2026-08-13, nothing at all from 2026-08-14, then v3
     * UUIDs from 2026-08-19 whose stability across deliveries is unverified —
     * duplicates were still arriving after that date. None of it is a key.
     *
     * Trade-off, the same one the positional-reference block below accepts:
     * with the reference gone, nothing distinguishes two genuinely identical
     * same-day transactions, so they collapse to one row. That is not
     * hypothetical here — production holds 230 groups of content-identical N26
     * rows, and roughly two thirds of the redundant rows arrived inside a
     * single API response, which is the bank stating a multiplicity we can no
     * longer represent. Making both cases right needs occurrence-aware dedup in
     * the consumer, which the `(account_id, dedup_fingerprint)` unique index
     * forbids today; tracked as a follow-up.
     *
     * Note this changes the fingerprint of rows already stored, so the first
     * sync after deploy does not recognise the ones whose key moved and imports
     * one more copy of them. Bounded to the 3-day watermark overlap (30 live
     * rows when this shipped) and one-off: rows written from here on carry the
     * new value. Deliberately not repaired — the duplicates already in the
     * database are being left for users to delete themselves.
     *
     * @var list<string>
     */
    private const array UNSTABLE_ID_BANKS = ['n26'];

    /**
     * The un-posted form of a card payment, and the settled form of the same
     * purchase. N26 delivers both, flipping this one content field in between;
     * it is the only field that ever varies inside a duplicate group (verified
     * across all 59 production groups whose code moves). Canonicalizing the
     * pair keeps every other transaction code discriminating, where dropping
     * the field would not.
     *
     * @var list<string>
     */
    private const array PENDING_CARD_CODE = ['MCRD', 'UPCT'];

    /** @var list<string> */
    private const array SETTLED_CARD_CODE = ['CCRD', 'POSD'];

    /**
     * Enable Banking statuses describing a delivery that has not settled:
     * PDNG pending, HOLD card authorisation hold, SCHD scheduled, CNCL
     * cancelled, RJCT rejected. A bank hands a purchase over as one of these
     * first and re-sends it later as BOOK with different content, so storing
     * the un-settled form guarantees a duplicate once the settled form lands.
     *
     * This is the second signal of the same phenomenon, kept next to the
     * card-code pair above because the two read different fields and do not
     * overlap. Banks that populate `status` leave the card code alone —
     * Revolut, Santander and Sabadell hold 5.6k of the 7.6k PDNG rows in
     * production. N26 is the mirror image: `status` is BOOK on both copies of
     * every row since July, and only `bank_transaction_code` moves. Whichever
     * field a bank uses, one of the two filters catches it; neither covers the
     * other's banks, so both have to stay.
     *
     * HOLD and SCHD have no production rows yet and come from the Berlin Group
     * status set rather than an observed payload. CNCL and RJCT are terminal
     * rather than waiting to settle: there is no BOOK copy coming, and no money
     * moved, so the ledger is right to omit them too.
     *
     * Filtered here rather than through the API's own `transaction_status`
     * query parameter because not every ASPSP populates `status`; filtering
     * server-side risks empty responses from the banks that never send it.
     * Anything absent from this list — BOOK, OTHR, or no field at all —
     * imports as before.
     *
     * @var list<string>
     */
    private const array UNSETTLED_STATUSES = ['PDNG', 'HOLD', 'SCHD', 'CNCL', 'RJCT'];

    /**
     * Whether the bank is handing over a delivery that has not settled, so the
     * caller can wait for the BOOK copy instead of storing both.
     *
     * @param  array<string, mixed>  $data
     */
    public static function isUnsettled(array $data): bool
    {
        return in_array($data['status'] ?? null, self::UNSETTLED_STATUSES, true);
    }

    private static function hasUnstableIds(?string $bankName): bool
    {
        return $bankName !== null && in_array(mb_strtolower($bankName), self::UNSTABLE_ID_BANKS, true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function for(array $data, ?string $bankName = null): string
    {
        $contentOnly = self::hasUnstableIds($bankName);

        if (! $contentOnly && ($data['transaction_id'] ?? null) !== null) {
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
        if (! $contentOnly && $entryReference !== null && ! self::isPositionalReference($entryReference)) {
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
            ...($contentOnly ? self::canonicalTransactionCode($data) : self::transactionCode($data)),
            $data['reference_number'] ?? '',
            self::remittance($data['remittance_information'] ?? []),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function transactionCode(array $data): array
    {
        return [$data['bank_transaction_code']['code'] ?? '', $data['bank_transaction_code']['sub_code'] ?? ''];
    }

    /**
     * The settled form of a card payment code, so the un-posted delivery of the
     * same purchase hashes identically.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function canonicalTransactionCode(array $data): array
    {
        $code = self::transactionCode($data);

        return $code === self::PENDING_CARD_CODE ? self::SETTLED_CARD_CODE : $code;
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
