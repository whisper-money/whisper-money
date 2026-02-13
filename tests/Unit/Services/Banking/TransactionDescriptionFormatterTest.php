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

test('does not transform description for non-BBVA bank', function () {
    $result = $this->formatter->format('ADEUDO DE ENDESA', 'ING');

    expect($result['description'])->toBe('ADEUDO DE ENDESA');
    expect($result['original_description'])->toBeNull();
});

test('does not transform description when bank name is null', function () {
    $result = $this->formatter->format('ADEUDO DE ENDESA', null);

    expect($result['description'])->toBe('ADEUDO DE ENDESA');
    expect($result['original_description'])->toBeNull();
});
