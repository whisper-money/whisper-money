<?php

use App\Support\Money;
use Tests\TestCase;

uses(TestCase::class);

it('formats minor units at the currency\'s own scale', function (int $minorUnits, string $currency, string $expected) {
    expect(Money::format($minorUnits, $currency))->toBe($expected);
})->with([
    'eur' => [399, 'eur', '€3.99'],
    'gbp' => [399, 'gbp', '£3.99'],
    'usd' => [399, 'usd', '$3.99'],
    'brl' => [399, 'brl', 'R$3.99'],
    'unknown symbol falls back to uppercased code' => [399, 'chf', 'CHF 3.99'],
    'currency match is case-insensitive' => [399, 'EUR', '€3.99'],
    'thousands separator' => [123456, 'eur', '€1,234.56'],
    'zero' => [0, 'eur', '€0.00'],
    // A zero-decimal currency stores whole units, so 100000 is a hundred
    // thousand yen, not a thousand.
    'jpy has no decimals' => [100000, 'jpy', '¥100,000'],
    'cop has no decimals' => [123456, 'cop', 'COP 123,456'],
    'kwd has three decimals' => [35135, 'kwd', 'KWD 35.135'],
    'btc has eight decimals' => [50000000, 'btc', 'BTC 0.50000000'],
]);

it('converts major units to the integer the database stores', function (float $major, string $currency, int $expected) {
    expect(Money::toMinor($major, $currency))->toBe($expected);
})->with([
    'eur' => [3.99, 'eur', 399],
    'cop keeps whole units' => [123456.0, 'cop', 123456],
    'cop rounds away a fraction it cannot store' => [1234.56, 'cop', 1235],
    'kwd' => [35.135, 'kwd', 35135],
    'btc down to the satoshi' => [0.00123456, 'btc', 123456],
    'negative' => [-3.99, 'eur', -399],
    'rounds half away from zero' => [3.995, 'eur', 400],
]);

it('converts stored integers back to major units', function () {
    expect(Money::toMajor(399, 'eur'))->toBe(3.99)
        ->and(Money::toMajor(123456, 'cop'))->toBe(123456.0)
        ->and(Money::toMajor(123456, 'btc'))->toBe(0.00123456);
});

it('scales one major unit into the right number of minor units', function () {
    expect(Money::toMinor(1.0, 'eur'))->toBe(100)
        ->and(Money::toMinor(1.0, 'cop'))->toBe(1)
        ->and(Money::toMinor(1.0, 'kwd'))->toBe(1000)
        ->and(Money::toMinor(1.0, 'btc'))->toBe(100_000_000);
});
