<?php

use App\Services\Banking\Formatters\RemittanceTagFormatter;

beforeEach(function () {
    $this->formatter = new RemittanceTagFormatter;
});

test('matches any bank whose description carries the remittance tag', function () {
    expect($this->formatter->matches('/TXT/D|BAR CONO', 'Bankinter'))->toBeTrue();
    expect($this->formatter->matches('/TXT/Amazon.es MP', 'Unicaja Banco'))->toBeTrue();
    expect($this->formatter->matches('/TXT/BAR CONO', null))->toBeTrue();
});

test('does not match untagged descriptions', function () {
    expect($this->formatter->matches('BAR CONO', 'Bankinter'))->toBeFalse();
    expect($this->formatter->matches('PAGO BIZUM A /TXT/', 'Bankinter'))->toBeFalse();
});

test('strips the remittance tag and the credit/debit marker', function (string $input, string $expected) {
    expect($this->formatter->format($input))->toBe($expected);
})->with([
    ['/TXT/D|SumUp *GELATERIA SALV', 'SumUp *GELATERIA SALV'],
    ['/TXT/D|APARCA & GO S.L.', 'APARCA & GO S.L.'],
    ['/TXT/H|PAGO BIZUM DE VICTORIA MOLLFU', 'PAGO BIZUM DE VICTORIA MOLLFU'],
    ['/TXT/H|TRANSF NOMI /AIGUA DE RIGAT, S', 'TRANSF NOMI /AIGUA DE RIGAT, S'],
    ['/TXT/Amazon.es MP', 'Amazon.es MP'],
]);

test('strips the purchase and settlement dates from card payments', function (string $input, string $expected) {
    expect($this->formatter->format($input))->toBe($expected);
})->with([
    ['/TXT/EL CLANDESTI 27/07/26|20260803', 'EL CLANDESTI'],
    ['/TXT/CONDIS SANT JUST DESV07/07/26|20260714', 'CONDIS SANT JUST DESV'],
    ['/TXT/WWW.AMAZON 06/07/26|20260713', 'WWW.AMAZON'],
]);

test('strips the internal marker on card charges', function () {
    expect($this->formatter->format('/TXT/D|#RECOBRO RECIBO VISA'))->toBe('RECOBRO RECIBO VISA');
});

test('collapses the padding banks leave inside the description', function () {
    expect($this->formatter->format('/TXT/D|PAGO   BIZUM A  MARC ORTEGA  '))->toBe('PAGO BIZUM A MARC ORTEGA');
});

test('keeps the original description when nothing is left after stripping', function (string $input) {
    expect($this->formatter->format($input))->toBe($input);
})->with([
    ['/TXT/'],
    ['/TXT/D|'],
    ['/TXT/ 27/07/26|20260803'],
]);
