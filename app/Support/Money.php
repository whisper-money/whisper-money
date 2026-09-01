<?php

namespace App\Support;

use App\Services\CurrencyOptions;
use NumberFormatter;

/**
 * Converts between a currency's minor units — the integers every money column
 * stores — and its major units, and formats them.
 *
 * The number of minor units per major unit depends on the currency: 100 for
 * EUR, 1 for COP, 100_000_000 for BTC. Nothing here may assume 2 decimals.
 */
final class Money
{
    public static function format(int $minorUnits, string $currency): string
    {
        $symbol = match (strtolower($currency)) {
            'eur' => '€',
            'gbp' => '£',
            'usd' => '$',
            'jpy' => '¥',
            'brl' => 'R$',
            default => strtoupper($currency).' ',
        };

        $decimals = self::decimals($currency);

        return $symbol.number_format(self::toMajor($minorUnits, $currency), $decimals);
    }

    /**
     * The same amount as the app itself prints it, in the reader's locale: a
     * Spanish reader gets "1.368,05 €", an English one "€1,368.05".
     *
     * {@see format()} is locale-blind and stays that way, because the emails
     * that already call it are written around its output. Anything the user is
     * meant to be able to reconcile against a screen in the product — the
     * monthly summary — comes through here instead, mirroring the frontend's
     * `formatCurrency`: Intl's rules, the currency's own decimals, and narrow
     * no-break spaces so the symbol never wraps away from its number.
     */
    public static function formatIn(int $minorUnits, string $currency, string $locale): string
    {
        $decimals = self::decimals($currency);

        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

        $formatted = $formatter->formatCurrency(self::toMajor($minorUnits, $currency), strtoupper($currency));

        // An unknown locale or currency leaves the failure on the formatter
        // rather than in the return value, so that is where it has to be read.
        if (intl_is_failure($formatter->getErrorCode())) {
            return self::format($minorUnits, $currency);
        }

        return preg_replace('/\s/u', "\u{202F}", $formatted) ?? $formatted;
    }

    /**
     * A major-unit amount as the integer the database stores, e.g. 3.99 EUR to
     * 399 and 0.5 BTC to 50_000_000.
     */
    public static function toMinor(float $majorUnits, string $currency): int
    {
        return (int) round($majorUnits * self::factor($currency));
    }

    /**
     * A stored integer back to major units, e.g. 399 EUR to 3.99. Lossy by
     * nature — the result is a float, so never round-trip it through arithmetic
     * that must balance.
     */
    public static function toMajor(int $minorUnits, string $currency): float
    {
        return $minorUnits / self::factor($currency);
    }

    /**
     * How many minor units make one major unit of this currency.
     */
    private static function factor(string $currency): int
    {
        return 10 ** self::decimals($currency);
    }

    private static function decimals(string $currency): int
    {
        return app(CurrencyOptions::class)->decimals($currency);
    }
}
