<?php

namespace App\Services\Achievements;

use App\Support\Money;

/**
 * The money thresholds for one currency, in that currency's minor units.
 *
 * Configured per currency in major units, because that is how a person reads
 * them, and scaled here once: COP has no centavos and EUR has cents, and a
 * threshold compared against stored integers has to be in the same scale as
 * the integers.
 */
class Ladders
{
    /**
     * The currency a reader's figures are measured in: their own when a ladder
     * exists for it, the fallback otherwise.
     */
    public function currencyFor(string $currency): string
    {
        $currency = strtoupper($currency);

        return array_key_exists($currency, config('achievements.ladders'))
            ? $currency
            : (string) config('achievements.fallback_currency');
    }

    /**
     * @return list<int> ascending thresholds in minor units
     */
    public function rungs(string $ladder, string $currency): array
    {
        $currency = $this->currencyFor($currency);
        $major = config("achievements.ladders.{$currency}.{$ladder}", []);

        return array_map(fn (int|float $value): int => Money::toMinor((float) $value, $currency), $major);
    }

    /**
     * The threshold of one rung, 1-based, or null past the top.
     */
    public function rung(string $ladder, int $tier, string $currency): ?int
    {
        return $this->rungs($ladder, $currency)[$tier - 1] ?? null;
    }
}
