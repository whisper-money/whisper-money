<?php

namespace App\Support;

/**
 * Formats a minor-unit amount (cents) with its currency symbol, e.g. "€3.99".
 *
 * Centralizes the money formatting that was duplicated across the stats report
 * commands and the Discord Stripe listener. Currencies without a known symbol
 * fall back to the uppercased currency code plus a trailing space ("CHF 3.99").
 */
final class Money
{
    public static function format(int $cents, string $currency): string
    {
        $symbol = match (strtolower($currency)) {
            'eur' => '€',
            'gbp' => '£',
            'usd' => '$',
            'jpy' => '¥',
            'brl' => 'R$',
            default => strtoupper($currency).' ',
        };

        return $symbol.number_format($cents / 100, 2);
    }
}
