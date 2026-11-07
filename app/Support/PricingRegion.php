<?php

namespace App\Support;

/**
 * Which regional price a user is offered: Latin America or everyone else.
 *
 * The browser's timezone decides, because it says where someone actually is
 * far more often than the currency they picked — hundreds of users in Bogotá,
 * Guayaquil or Buenos Aires keep their money in USD or EUR. Any `America/*`
 * zone counts unless its country is one of the North American ones (or a
 * North Atlantic territory filed under `America/*`). With no timezone, or one
 * that names no country, the currency is the fallback.
 *
 * This is the only copy of the rule: the frontend checks an anonymous
 * visitor's timezone against `latamTimezones()`, shared from here.
 */
final class PricingRegion
{
    /**
     * Countries with `America/*` zones that are not Latin America.
     */
    private const NON_LATAM_AMERICAN_COUNTRIES = ['US', 'CA', 'GL', 'PM', 'BM'];

    private const LATAM_CURRENCIES = [
        'MXN', 'COP', 'CLP', 'ARS', 'PEN', 'BRL', 'UYU', 'PYG',
        'BOB', 'VES', 'GTQ', 'HNL', 'NIO', 'CRC', 'DOP', 'CUP',
    ];

    /** @var list<string>|null */
    private static ?array $latamTimezones = null;

    public static function isLatam(?string $timezone, ?string $currency): bool
    {
        $country = TimezoneCountry::of($timezone);

        if ($country === null) {
            return in_array(strtoupper((string) $currency), self::LATAM_CURRENCIES, true);
        }

        return self::isLatamTimezone((string) $timezone, $country);
    }

    /**
     * Every timezone identifier the rule places in Latin America, legacy
     * `America/*` aliases included because older browsers still send them.
     *
     * @api Shared with the frontend for anonymous visitors in a follow-up;
     *      until then only the test suite calls it.
     *
     * @return list<string>
     */
    public static function latamTimezones(): array
    {
        return self::$latamTimezones ??= array_values(array_filter(
            \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC),
            fn (string $timezone): bool => self::isLatamTimezone($timezone, TimezoneCountry::of($timezone)),
        ));
    }

    private static function isLatamTimezone(string $timezone, ?string $country): bool
    {
        return str_starts_with($timezone, 'America/')
            && $country !== null
            && ! in_array($country, self::NON_LATAM_AMERICAN_COUNTRIES, true);
    }
}
