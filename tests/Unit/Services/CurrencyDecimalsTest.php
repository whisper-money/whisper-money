<?php

use App\Services\CurrencyOptions;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The scale of every currency that is not on the default, spelled out.
 *
 * This duplicates `config/currencies.php` deliberately. Money is *stored* at
 * these scales, so moving one is a data migration, not a config edit — and
 * editing the config alone turns this test red, which is the point.
 *
 * It cannot be derived from ICU. `NumberFormatter` disagrees with itself across
 * ICU versions: PKR reads 0 decimals on macOS and 2 on the CI runner. Deriving
 * the stored scale from the host's ICU would make the same row mean different
 * amounts on different machines, and an ICU upgrade a silent data migration.
 */
const EXPECTED_DECIMALS = [
    // No minor unit in practice, whatever ISO 4217 says about centavos.
    'COP' => 0,
    'CLP' => 0,
    'PYG' => 0,
    'JPY' => 0,
    'PKR' => 0,
    'KWD' => 3,
    // Not an ISO currency. Divisible to the satoshi, and users hold fractions
    // far below 0.01.
    'BTC' => 8,
];

it('keeps every currency at its documented scale', function () {
    $options = new CurrencyOptions;

    foreach ($options->decimalsMap() as $code => $decimals) {
        expect($decimals)->toBe(
            EXPECTED_DECIMALS[$code] ?? CurrencyOptions::DEFAULT_DECIMALS,
            "{$code} is configured with {$decimals} decimals. Stored money uses "
            .'this scale, so changing it requires a migration that rescales the '
            .'existing rows; update EXPECTED_DECIMALS only alongside one.'
        );
    }
});

it('has no stale entry for a currency the app no longer offers', function () {
    $configured = array_keys((new CurrencyOptions)->decimalsMap());

    expect(array_diff(array_keys(EXPECTED_DECIMALS), $configured))->toBeEmpty();
});

it('spells out a scale for every configured currency', function () {
    $options = new CurrencyOptions;

    expect($options->decimalsMap())->toHaveCount(count($options->all()));
});

it('falls back to two decimals for a currency it does not know', function () {
    expect((new CurrencyOptions)->decimals('XXX'))->toBe(CurrencyOptions::DEFAULT_DECIMALS);
});
