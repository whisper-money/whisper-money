<?php

use App\Services\CurrencyOptions;
use Tests\TestCase;

/**
 * The stored scale of every money column comes from `config/currencies.php`, so
 * a wrong entry silently corrupts data rather than merely rendering oddly. ICU
 * already knows the right answer for every ISO currency, so this compares the
 * two and fails on drift — but the config stays the source of truth, because
 * deriving the storage scale from the server's ICU version would let an ICU
 * upgrade rescale the database underneath us.
 */
uses(TestCase::class);

/** Currencies where we deliberately disagree with CLDR, and why. */
const DECLARED_OVERRIDES = [
    // Not an ISO currency, so CLDR falls back to 2. Bitcoin is divisible to
    // 8 decimals (one satoshi) and users hold fractions far below 0.01.
    'BTC' => 8,
];

it('matches CLDR for every currency except the declared overrides', function () {
    $options = new CurrencyOptions;

    foreach ($options->decimalsMap() as $code => $decimals) {
        if (array_key_exists($code, DECLARED_OVERRIDES)) {
            expect($decimals)->toBe(
                DECLARED_OVERRIDES[$code],
                "{$code} is a declared override and must keep its documented scale"
            );

            continue;
        }

        $formatter = new NumberFormatter('en_US', NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $code);
        $cldr = $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);

        expect($decimals)->toBe(
            $cldr,
            "{$code} is configured with {$decimals} decimals but CLDR says {$cldr}. ".
            'Either fix the config or add it to DECLARED_OVERRIDES with a reason.'
        );
    }
});

it('spells out a scale for every configured currency', function () {
    $options = new CurrencyOptions;

    expect($options->decimalsMap())->toHaveCount(count($options->all()));
});

it('falls back to two decimals for a currency it does not know', function () {
    expect((new CurrencyOptions)->decimals('XXX'))->toBe(CurrencyOptions::DEFAULT_DECIMALS);
});
