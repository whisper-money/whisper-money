<?php

namespace App\Enums;

enum Locale: string
{
    case English = 'en';
    case Spanish = 'es';
    case French = 'fr';

    /**
     * Detect the best-matching locale from an Accept-Language header,
     * falling back to English.
     */
    public static function detectFromHeader(?string $acceptLanguage): self
    {
        $acceptLanguage ??= '';

        foreach ([self::Spanish, self::French] as $locale) {
            if ($acceptLanguage === $locale->value || preg_match('/^'.$locale->value.'(-|,|;)/i', $acceptLanguage) === 1) {
                return $locale;
            }
        }

        return self::English;
    }
}
