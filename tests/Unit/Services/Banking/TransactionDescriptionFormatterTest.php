<?php

use App\Services\Banking\TransactionDescriptionFormatter;

beforeEach(function () {
    $this->formatter = new TransactionDescriptionFormatter;
});

test('formats description for BBVA bank and stores original', function () {
    $result = $this->formatter->format('ADEUDO DE ENDESA // PAGO SEPA', 'BBVA');

    expect($result['description'])->toBe('Adeudo de Endesa / Pago SEPA');
    expect($result['original_description'])->toBe('ADEUDO DE ENDESA // PAGO SEPA');
});

test('does not set original_description when formatting produces no change', function () {
    $result = $this->formatter->format('Already Nice Description', 'BBVA');

    expect($result['description'])->toBe('Already Nice Description');
    expect($result['original_description'])->toBeNull();
});

test('strips the remittance tag and stores the original, whatever the bank', function (?string $bank) {
    $result = $this->formatter->format('/TXT/D|BAR CONO', $bank);

    expect($result['description'])->toBe('BAR CONO');
    expect($result['original_description'])->toBe('/TXT/D|BAR CONO');
})->with([
    'a bank without its own formatter' => 'Bankinter',
    'an unknown bank' => null,
]);

test('a tagged description is handled by the remittance formatter, not the bank one', function () {
    $result = $this->formatter->format('/TXT/D|ADEUDO DE ENDESA', 'BBVA');

    expect($result['description'])->toBe('ADEUDO DE ENDESA');
});

test('does not transform description for a bank other than BBVA', function (?string $bank) {
    $result = $this->formatter->format('ADEUDO DE ENDESA', $bank);

    expect($result['description'])->toBe('ADEUDO DE ENDESA');
    expect($result['original_description'])->toBeNull();
})->with([
    'non-BBVA bank' => 'ING',
    'no bank name' => null,
]);
