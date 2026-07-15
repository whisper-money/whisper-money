<?php

use App\Support\Money;

it('formats cents with the currency symbol', function (int $cents, string $currency, string $expected) {
    expect(Money::format($cents, $currency))->toBe($expected);
})->with([
    'eur' => [399, 'eur', '€3.99'],
    'gbp' => [399, 'gbp', '£3.99'],
    'usd' => [399, 'usd', '$3.99'],
    'jpy' => [100000, 'jpy', '¥1,000.00'],
    'brl' => [399, 'brl', 'R$3.99'],
    'unknown currency falls back to uppercased code' => [399, 'chf', 'CHF 3.99'],
    'currency match is case-insensitive' => [399, 'EUR', '€3.99'],
    'thousands separator' => [123456, 'eur', '€1,234.56'],
    'zero' => [0, 'eur', '€0.00'],
]);
