<?php

namespace App\Support;

use NumberFormatter;

/**
 * Locale-aware formatting for the figures that are not money: percentages,
 * counts and month names.
 *
 * The monthly summary and its shareable cards quote a lot of percentages, and a
 * Spanish reader expects "35,5 %" where an English one expects "35.5%". Getting
 * that wrong is the kind of detail that makes a report look machine-made.
 */
final class Figures
{
    /**
     * A percentage as the reader's locale writes it, sign included when asked
     * for — a net worth card leads with "+18,4 %" and the plus is the point.
     */
    public static function percent(float $value, string $locale, bool $signed = false, int $decimals = 1): string
    {
        $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

        if ($signed) {
            $formatter->setTextAttribute(NumberFormatter::POSITIVE_PREFIX, '+');
        }

        // Spanish and French put a space before the sign, English does not.
        // ICU knows which; asking it for a percent pattern also multiplies by
        // 100, so the space is applied here instead.
        $separator = in_array(substr($locale, 0, 2), ['es', 'fr'], true) ? "\u{202F}" : '';

        return $formatter->format($value).$separator.'%';
    }

    /**
     * A plain integer with the locale's thousands separator.
     */
    public static function count(int $value, string $locale): string
    {
        return (new NumberFormatter($locale, NumberFormatter::DECIMAL))->format($value);
    }
}
