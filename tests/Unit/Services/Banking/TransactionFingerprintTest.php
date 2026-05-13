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
