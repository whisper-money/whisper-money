<?php

use App\Support\PricingRegion;

it('places a Latin American timezone in the latam region whatever the currency', function (string $timezone, ?string $currency) {
    expect(PricingRegion::isLatam($timezone, $currency))->toBeTrue();
})->with([
    'Mexico in pesos' => ['America/Mexico_City', 'MXN'],
    'Colombia in dollars' => ['America/Bogota', 'USD'],
    'Ecuador in dollars' => ['America/Guayaquil', 'USD'],
    'Argentina in euros' => ['America/Argentina/Buenos_Aires', 'EUR'],
    'Brazil' => ['America/Sao_Paulo', 'BRL'],
    'a Brazilian zone far from São Paulo' => ['America/Manaus', 'USD'],
    'Venezuela with no currency' => ['America/Caracas', null],
    'a legacy alias' => ['America/Buenos_Aires', 'USD'],
]);

it('keeps the rest of the Americas and the world out of the latam region', function (string $timezone, ?string $currency) {
    expect(PricingRegion::isLatam($timezone, $currency))->toBeFalse();
})->with([
    'United States' => ['America/New_York', 'USD'],
    'United States in pesos' => ['America/Los_Angeles', 'MXN'],
    'Canada' => ['America/Toronto', 'CAD'],
    'Greenland' => ['America/Nuuk', 'DKK'],
    'Saint Pierre and Miquelon' => ['America/Miquelon', 'EUR'],
    'Bermuda, filed under Atlantic' => ['Atlantic/Bermuda', 'USD'],
    'Spain in pesos' => ['Europe/Madrid', 'MXN'],
    'Spain' => ['Europe/Madrid', 'EUR'],
]);

it('falls back to the currency when the timezone names no country', function (?string $timezone, ?string $currency, bool $isLatam) {
    expect(PricingRegion::isLatam($timezone, $currency))->toBe($isLatam);
})->with([
    'no timezone, Colombian pesos' => [null, 'COP', true],
    'no timezone, reais' => [null, 'BRL', true],
    'no timezone, lower-case pesos' => [null, 'mxn', true],
    'no timezone, euros' => [null, 'EUR', false],
    'no timezone, no currency' => [null, null, false],
    'empty timezone, Chilean pesos' => ['', 'CLP', true],
    'invalid timezone, Argentine pesos' => ['Not/AZone', 'ARS', true],
    'invalid timezone, dollars' => ['Not/AZone', 'USD', false],
    'UTC, Peruvian soles' => ['UTC', 'PEN', true],
]);

it('lists every timezone the rule places in Latin America and nothing else', function () {
    $timezones = PricingRegion::latamTimezones();

    expect($timezones)
        ->toContain('America/Mexico_City', 'America/Bogota', 'America/Sao_Paulo', 'America/Buenos_Aires')
        ->not->toContain('America/New_York', 'America/Toronto', 'America/Nuuk', 'America/Miquelon', 'Europe/Madrid', 'UTC')
        ->each->toStartWith('America/');

    foreach ($timezones as $timezone) {
        expect(PricingRegion::isLatam($timezone, 'EUR'))->toBeTrue();
    }
});
