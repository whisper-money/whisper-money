<?php

namespace App\Support;

/**
 * The country an IANA timezone sits in: "Europe/Madrid" is ES,
 * "America/Bogota" is CO.
 *
 * A signup's browser timezone says where someone actually is far more often
 * than the language their browser shipped with: a third of the `es-ES` signups
 * live in America, and `es-419` names no country at all.
 */
class TimezoneCountry
{
    /**
     * The two-letter country code, or null for a zone with no country (UTC) or
     * one neither tzdata nor ICU knows.
     *
     * PHP's tzdata knows the country of every canonical zone but answers "??"
     * for a legacy alias like "Asia/Calcutta", which older browsers still
     * send, so those are asked of ICU instead.
     */
    public static function of(?string $timezone): ?string
    {
        if ($timezone === null || $timezone === '') {
            return null;
        }

        try {
            $location = (new \DateTimeZone($timezone))->getLocation();
        } catch (\Exception) {
            return null;
        }

        if ($location !== false && $location['country_code'] !== '??') {
            return $location['country_code'];
        }

        if (! class_exists(\IntlTimeZone::class)) {
            return null;
        }

        $region = \IntlTimeZone::getRegion($timezone) ?: '';

        // ICU answers "001", the world, for a zone like UTC that has no country.
        return preg_match('/^[A-Z]{2}$/', $region) === 1 ? $region : null;
    }
}
