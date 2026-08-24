<?php

use App\Services\Banking\TransactionFingerprint;

test('external transaction id is the canonical fingerprint when present', function () {
    $payload = baseEnableBankingPayload([
        'transaction_id' => 'txn-123',
        'entry_reference' => 'entry-456',
    ]);

    $changedPayload = baseEnableBankingPayload([
        'transaction_id' => 'txn-123',
        'entry_reference' => 'different-entry',
        'booking_date' => '2025-05-13',
        'value_date' => '2025-05-14',
        'transaction_date' => '2025-05-15',
        'status' => 'BOOK',
        'transaction_amount' => ['amount' => '999.99', 'currency' => 'USD'],
    ]);

    expect(TransactionFingerprint::for($payload))
        ->toBe(TransactionFingerprint::for($changedPayload));
});

test('entry reference is the canonical fingerprint when transaction id is absent', function () {
    $payload = baseEnableBankingPayload([
        'transaction_id' => null,
        'entry_reference' => 'entry-456',
    ]);

    $changedPayload = baseEnableBankingPayload([
        'transaction_id' => null,
        'entry_reference' => 'entry-456',
        'booking_date' => '2025-05-13',
        'value_date' => '2025-05-14',
        'status' => 'BOOK',
        'creditor' => ['name' => 'Different Merchant'],
    ]);

    expect(TransactionFingerprint::for($payload))
        ->toBe(TransactionFingerprint::for($changedPayload));
});

test('positional entry reference is ignored so it matches the same transaction seen without one', function () {
    $firstSync = baseEnableBankingPayload([
        'transaction_id' => null,
        'entry_reference' => null,
    ]);

    $laterSync = baseEnableBankingPayload([
        'transaction_id' => null,
        'entry_reference' => '2025-05-12.0',
    ]);

    expect(TransactionFingerprint::for($firstSync))
        ->toBe(TransactionFingerprint::for($laterSync));
});

test('a non-positional entry reference is still the canonical fingerprint', function () {
    // Only the exact `{date}.{index}` shape is treated as positional; anything
    // else keeps keying on entry_reference so real bank references are honoured.
    foreach (['2025-05-12', '2025-05-12.0.1', 'REF-2025-05-12.0', '2025-05-12.'] as $reference) {
        $withReference = baseEnableBankingPayload([
            'transaction_id' => null,
            'entry_reference' => $reference,
        ]);

        $sameReferenceOtherContent = baseEnableBankingPayload([
            'transaction_id' => null,
            'entry_reference' => $reference,
            'creditor' => ['name' => 'Different Merchant'],
        ]);

        expect(TransactionFingerprint::for($withReference))
            ->toBe(TransactionFingerprint::for($sameReferenceOtherContent));
    }
});

test('fallback fingerprint ignores volatile status and settlement dates', function () {
    $payload = baseEnableBankingPayload([
        'transaction_id' => null,
        'entry_reference' => null,
        'status' => 'PDNG',
        'value_date' => null,
        'transaction_date' => '2025-05-12',
    ]);

    $settledPayload = baseEnableBankingPayload([
        'transaction_id' => null,
        'entry_reference' => null,
        'status' => 'BOOK',
        'value_date' => '2025-05-14',
        'transaction_date' => '2025-05-13',
    ]);

    expect(TransactionFingerprint::for($payload))
        ->toBe(TransactionFingerprint::for($settledPayload));
});

test('fallback fingerprint includes booking date for null id transactions', function () {
    $payload = baseEnableBankingPayload([
        'transaction_id' => null,
        'entry_reference' => null,
        'booking_date' => '2025-05-12',
    ]);

    $nextDayPayload = baseEnableBankingPayload([
        'transaction_id' => null,
        'entry_reference' => null,
        'booking_date' => '2025-05-13',
    ]);

    expect(TransactionFingerprint::for($payload))
        ->not->toBe(TransactionFingerprint::for($nextDayPayload));
});

test('n26 fingerprints the pending and the settled delivery of a card payment identically', function () {
    // Real prod payloads for the same purchase: N26 delivers a card payment
    // twice, minting a fresh entry_reference and flipping the transaction code
    // from "un-posted card txn" to "point-of-sale debit" on settlement. Those
    // two fields are the only ones that differ, out of 24.
    $pending = baseEnableBankingPayload([
        'entry_reference' => '95d1119e-92d2-11f1-a38e-4d2ca6090c88',
        'booking_date' => '2026-08-08',
        'transaction_amount' => ['amount' => '13.80', 'currency' => 'EUR'],
        'creditor' => ['name' => 'EL QUEMAITO'],
        'bank_transaction_code' => ['code' => 'MCRD', 'sub_code' => 'UPCT'],
    ]);

    $settled = array_replace($pending, [
        'entry_reference' => '95d11175-92d2-11f1-822e-49adef1560a6',
        'bank_transaction_code' => ['code' => 'CCRD', 'sub_code' => 'POSD'],
    ]);

    expect(TransactionFingerprint::for($pending, 'N26'))
        ->toBe(TransactionFingerprint::for($settled, 'N26'));

    // Without the bank name nothing changes for every other ASPSP: both keys
    // still move, which is exactly what imported the transaction twice.
    expect(TransactionFingerprint::for($pending))
        ->not->toBe(TransactionFingerprint::for($settled));
});

test('n26 fingerprints a transaction the same with and without an entry reference', function () {
    // On 2026-08-14 N26 stopped sending entry_reference altogether, so every
    // already-imported transaction still inside the sync overlap window came
    // back looking brand new.
    $withReference = baseEnableBankingPayload([
        'entry_reference' => '95d1119e-92d2-11f1-a38e-4d2ca6090c88',
        'bank_transaction_code' => ['code' => 'CCRD', 'sub_code' => 'POSD'],
    ]);

    $withoutReference = array_replace($withReference, ['entry_reference' => null]);

    expect(TransactionFingerprint::for($withReference, 'N26'))
        ->toBe(TransactionFingerprint::for($withoutReference, 'N26'));
});

test('a bank with stable ids still keys on its own transaction id and entry reference', function () {
    $payload = baseEnableBankingPayload(['entry_reference' => 'entry-456']);
    $sameReference = baseEnableBankingPayload([
        'entry_reference' => 'entry-456',
        'creditor' => ['name' => 'Different Merchant'],
    ]);
    $otherReference = baseEnableBankingPayload(['entry_reference' => 'entry-789']);

    expect(TransactionFingerprint::for($payload, 'BBVA'))
        ->toBe(TransactionFingerprint::for($sameReference, 'BBVA'))
        ->and(TransactionFingerprint::for($payload, 'BBVA'))
        ->not->toBe(TransactionFingerprint::for($otherReference, 'BBVA'));
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function baseEnableBankingPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'transaction_id' => null,
        'entry_reference' => null,
        'status' => 'OTHR',
        'booking_date' => '2025-05-12',
        'value_date' => null,
        'transaction_date' => '2025-05-12',
        'transaction_amount' => ['amount' => '59.61', 'currency' => 'USD'],
        'credit_debit_indicator' => 'DBIT',
        'creditor' => ['name' => 'MoonPay*Phantom 2880'],
        'debtor' => ['name' => null],
        'creditor_account' => ['iban' => null, 'other' => ['identification' => null]],
        'debtor_account' => ['iban' => null, 'other' => ['identification' => '487104XXXXXX1158']],
        'bank_transaction_code' => ['code' => 'CCRD', 'sub_code' => 'POSD'],
        'reference_number' => null,
        'remittance_information' => [],
    ], $overrides);
}
