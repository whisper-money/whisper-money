<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\AcceptHeader;

/**
 * The locale a reader's amounts and dates are written in.
 *
 * Sibling of {@see CurrencyOptions}: one closed list in `config/format_locales.php`,
 * read here and nowhere else. It is deliberately not the app locale — that one
 * names the language `lang/` is translated into and stays two letters, while
 * this one carries the region that decides where the decimal separator goes.
 */
class FormatLocaleOptions
{
    /**
     * @return list<string>
     */
    public function codes(): array
    {
        /** @var list<string> $codes */
        $codes = config('format_locales.options', []);

        return $codes;
    }

    /**
     * The region a browser is asking for, or the language's own default when it
     * names none the app knows.
     *
     * The header is read in the browser's own order of preference — Symfony's
     * parser sorts on `q` — and the first tag on the list wins. A tag we do not
     * offer is skipped rather than widened to its language: "de-DE" has no
     * German list to fall back to, and guessing a region from a language is how
     * a Mexican ended up reading Spain's separators in the first place.
     */
    public function detectFromHeader(?string $acceptLanguage, ?string $language): string
    {
        $supported = $this->byLowercaseCode();

        foreach (array_keys(AcceptHeader::fromString($acceptLanguage ?? '')->all()) as $tag) {
            $code = $supported[strtolower(str_replace('_', '-', $tag))] ?? null;

            if ($code !== null) {
                return $code;
            }
        }

        return $this->fallbackFor($language);
    }

    /**
     * What this language formatted like before the region existed, so a reader
     * the header says nothing useful about is left exactly where they were.
     */
    public function fallbackFor(?string $language): string
    {
        /** @var array<string, string> $fallbacks */
        $fallbacks = config('format_locales.fallbacks', []);

        return $fallbacks[substr((string) $language, 0, 2)] ?? (string) config('format_locales.default', 'en-US');
    }

    /**
     * @return array<string, string>
     */
    private function byLowercaseCode(): array
    {
        $codes = $this->codes();

        return array_combine(array_map(strtolower(...), $codes), $codes);
    }
}
