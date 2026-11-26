<?php

use App\Services\Forecast\DescriptionFingerprint;

it('gives the same fingerprint to charges whose references and dates change', function (string $first, string $second, string $expected) {
    $fingerprint = app(DescriptionFingerprint::class);

    expect($fingerprint->of($first))->toBe($expected)
        ->and($fingerprint->of($second))->toBe($expected);
})->with([
    'utility bill' => ['RECIBO IBERDROLA REF 88412 01/11', 'Recibo Iberdrola ref. 90233 - 01/12', 'recibo iberdrola'],
    'iban and mandate' => ['ADEUDO SEPA ES9121000418450200051332 MANDATO 7781 GIMNASIO FIT', 'Adeudo SEPA ES7620770024003102575766 mandato 8812 Gimnasio Fit', 'adeudo sepa gimnasio fit'],
    'month names' => ['CUOTA COLEGIO NOVIEMBRE 2026', 'Cuota colegio diciembre 2026', 'cuota colegio'],
    'glued punctuation' => ['NETFLIX.COM*A7X9K2', 'NETFLIX.COM*B88ZQ1', 'netflix com'],
]);

it('keeps accents, so a payee spelt with them stays itself', function () {
    expect(app(DescriptionFingerprint::class)->of('NÓMINA ACME SL 2026-10-30'))->toBe('nómina acme');
});

it('prefers the creditor name when the bank gives one', function () {
    $fingerprint = app(DescriptionFingerprint::class);

    expect($fingerprint->of('TRANSFERENCIA 88123', 'Endesa Energía S.A.'))->toBe('endesa energía')
        ->and($fingerprint->of('Recibo Endesa 01/11', '  '))->toBe('recibo endesa')
        ->and($fingerprint->of('Recibo Endesa 01/11', '0012'))->toBe('recibo endesa');
});

it('says nothing stable when a description is only numbers and references', function () {
    expect(app(DescriptionFingerprint::class)->of('REF 1234 / 01-11-2026'))->toBe('');
});
