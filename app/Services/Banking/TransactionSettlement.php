<?php

namespace App\Services\Banking;

/**
 * Whether an EnableBanking payload is the settled delivery of a transaction or
 * an earlier, un-settled form of the same one.
 *
 * Banks signal this in one of two fields, and never in both. Which one they use
 * decides which consumer acts on it: `status` gates the import in
 * TransactionSyncService, `bank_transaction_code` is canonicalized into the
 * content hash in TransactionFingerprint. They live together here because they
 * answer the same question, and because reading them apart invites the wrong
 * conclusion — that either one made the other redundant.
 */
class TransactionSettlement
{
    /**
     * Statuses describing a delivery that has not settled: PDNG pending, HOLD
     * card authorisation hold, SCHD scheduled, CNCL cancelled, RJCT rejected.
     * A bank hands a purchase over as one of these first and re-sends it later
     * as BOOK with different content, so storing the un-settled form
     * guarantees a duplicate once the settled form lands.
     *
     * HOLD and SCHD have no production rows yet and come from the Berlin Group
     * status set rather than an observed payload. CNCL and RJCT are terminal
     * rather than waiting to settle: no BOOK copy is coming and no money
     * moved, so the ledger is right to omit them too.
     *
     * Read here rather than through the API's own `transaction_status` query
     * parameter because not every ASPSP populates `status`; filtering
     * server-side risks empty responses from the banks that never send it.
     * Anything absent from this list — BOOK, OTHR, or no field at all —
     * imports as before.
     *
     * @var list<string>
     */
    private const array UNSETTLED_STATUSES = ['PDNG', 'HOLD', 'SCHD', 'CNCL', 'RJCT'];

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
     * Whether the bank is handing over a delivery that has not settled, so the
     * caller can wait for the BOOK copy instead of storing both.
     *
     * Banks that populate `status` leave the card code alone — Revolut,
     * Santander and Sabadell hold 5.6k of the 7.6k PDNG rows in production.
     * N26 is the mirror image: `status` is BOOK on both copies of every row
     * since July, and only `bank_transaction_code` moves. So this catches
     * nothing for N26, and canonicalCardCode() catches nothing for the others.
     *
     * @param  array<string, mixed>  $data
     */
    public static function isUnsettled(array $data): bool
    {
        return in_array($data['status'] ?? null, self::UNSETTLED_STATUSES, true);
    }

    /**
     * The transaction code as the bank sent it.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function cardCode(array $data): array
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
    public static function canonicalCardCode(array $data): array
    {
        $code = self::cardCode($data);

        return $code === self::PENDING_CARD_CODE ? self::SETTLED_CARD_CODE : $code;
    }
}
