<?php

use App\Services\Banking\Formatters\BbvaFormatter;

beforeEach(function () {
    $this->formatter = new BbvaFormatter;
});

test('matches BBVA bank name case-insensitively', function () {
    expect($this->formatter->matches('BBVA'))->toBeTrue();
    expect($this->formatter->matches('bbva'))->toBeTrue();
    expect($this->formatter->matches('Bbva'))->toBeTrue();
});

test('does not match other banks', function () {
    expect($this->formatter->matches('ING'))->toBeFalse();
    expect($this->formatter->matches('Santander'))->toBeFalse();
});

test('formats ALL CAPS description with // separators to Title Case', function () {
    $input = 'ADEUDO DE ENDESA // PAGO DE ADEUDO DIRECTO SEPA // N 2026041001476680 ENDESA ENERGIA S.A.                  CPVR';
    $result = $this->formatter->format($input);

    expect($result)->toBe('Adeudo de Endesa / Pago de Adeudo Directo SEPA / N 2026041001476680 Endesa Energia S.A. CPVR');
});

test('preserves acronyms in uppercase', function () {
    $input = 'TRANSFERENCIA SEPA BIZUM ATM';
    $result = $this->formatter->format($input);

    expect($result)->toContain('SEPA');
    expect($result)->toContain('BIZUM');
    expect($result)->toContain('ATM');
});

test('preserves S.A. and S.L. patterns', function () {
    $input = 'PAGO A ENDESA ENERGIA SA';
    $result = $this->formatter->format($input);

    expect($result)->toContain('S.A.');
});

test('lowercases Spanish stopwords except at segment start', function () {
    $input = 'PAGO DE LA FACTURA // DE LOS SERVICIOS';
    $result = $this->formatter->format($input);

    expect($result)->toBe('Pago de la Factura / De los Servicios');
});

test('capitalizes first word of each segment', function () {
    $input = 'DE LA CUENTA // EN EL BANCO';
    $result = $this->formatter->format($input);

    expect($result)->toStartWith('De la');
    expect($result)->toContain('/ En el');
});

test('preserves reference numbers as-is', function () {
    $input = 'TRANSFERENCIA N 2026041001476680 PARA EMPRESA';
    $result = $this->formatter->format($input);

    expect($result)->toContain('N 2026041001476680');
});

test('collapses multiple whitespace', function () {
    $input = 'PAGO    DE    SERVICIOS';
    $result = $this->formatter->format($input);

    expect($result)->not->toContain('  ');
});

test('does not transform mixed-case descriptions', function () {
    $input = 'Grocery Store Purchase';
    $result = $this->formatter->format($input);

    expect($result)->toBe('Grocery Store Purchase');
});

test('handles empty segments from double slashes', function () {
    $input = 'CONCEPTO // // DETALLE';
    $result = $this->formatter->format($input);

    expect($result)->toBe('Concepto / Detalle');
});
