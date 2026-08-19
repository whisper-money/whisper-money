<?php

namespace App\Support\Marketing;

/**
 * The public integrations page: what a visitor can connect, before registering.
 *
 * One page per language rather than one per bank. A few thousand pages that
 * differ only in a name is a doorway pattern, and the SEO value is in the names
 * being in the served HTML, which a single page already gives us.
 *
 * The bank catalogue is read from a file committed to the repo, refreshed by
 * `banks:sync-institutions`. Calling the open banking provider per request
 * would give a marketing page a dependency that can time out, and the catalogue
 * changes a handful of times a year.
 */
final class IntegrationsPage
{
    /**
     * Written by App\Console\Commands\SyncBankInstitutionsCommand.
     */
    public const CATALOGUE = 'resources/data/bank-institutions.json';

    /**
     * The page copy with the catalogue's own figures substituted in, so the
     * counts in the prose cannot disagree with the list underneath them.
     *
     * @return array<string, mixed>
     */
    public static function content(string $locale): array
    {
        $groups = self::countries($locale);

        $replacements = [
            ':count' => number_format(array_sum(array_map(fn (array $group): int => count($group['banks']), $groups))),
            ':countries' => (string) count($groups),
        ];

        return array_map(
            fn (mixed $value): mixed => is_string($value) ? strtr($value, $replacements) : $value,
            MarketingContent::integrations($locale),
        );
    }

    /**
     * The catalogue grouped by country, each country named in this language and
     * its banks sorted, in the order the connect flow offers the countries.
     *
     * @return list<array{code: string, name: string, banks: list<string>}>
     */
    public static function countries(string $locale): array
    {
        $catalogue = self::catalogue();
        $groups = [];

        foreach (MarketingContent::COUNTRIES as $code => $names) {
            $banks = $catalogue[$code] ?? [];

            if ($banks === []) {
                continue;
            }

            $groups[] = ['code' => $code, 'name' => $names[$locale] ?? $names['en'], 'banks' => $banks];
        }

        return $groups;
    }

    /**
     * The API-key integrations with their description in this language.
     *
     * @return list<array{name: string, description: string}>
     */
    public static function providers(string $locale): array
    {
        $providers = [];

        foreach (MarketingContent::apiProviders() as $name => $description) {
            $providers[] = ['name' => $name, 'description' => $description[$locale] ?? $description['en']];
        }

        return $providers;
    }

    /**
     * Path for the page in a language, without a leading slash.
     *
     * @api Consumed by routes/web.php and by the test suite, neither of which
     *      static analysis scans.
     */
    public static function path(string $locale): string
    {
        return MarketingContent::INTEGRATION_PATHS[$locale] ?? MarketingContent::INTEGRATION_PATHS['en'];
    }

    /**
     * Absolute URL for the page in a language.
     */
    public static function url(string $locale): string
    {
        return rtrim((string) config('app.url'), '/').'/'.self::path($locale);
    }

    /**
     * Every language the page exists in, for hreflang and the language switch.
     *
     * @return array<string, string>
     */
    public static function alternates(): array
    {
        $alternates = [];

        foreach (MarketingContent::LOCALES as $locale) {
            $alternates[$locale] = self::url($locale);
        }

        return $alternates;
    }

    /**
     * Title and path in one language, for the landing footer and llms.txt. A
     * language the page is not published in falls back to English.
     *
     * @return array{heading: string, path: string, url: string}
     */
    public static function link(string $locale): array
    {
        $locale = in_array($locale, MarketingContent::LOCALES, true) ? $locale : 'en';

        return [
            'heading' => MarketingContent::integrations($locale)['heading'],
            'path' => '/'.self::path($locale),
            'url' => self::url($locale),
        ];
    }

    /**
     * The committed catalogue, keyed by country code. A missing file leaves the
     * page standing with no bank list rather than 500ing on a marketing URL.
     *
     * @return array<string, list<string>>
     */
    private static function catalogue(): array
    {
        $path = base_path(self::CATALOGUE);

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded['countries'] ?? null) ? $decoded['countries'] : [];
    }
}
