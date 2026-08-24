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
     * The provider's own test institution, which is not a bank anyone can
     * connect and so never reaches a public page.
     */
    public const TEST_INSTITUTION = 'Mock ASPSP';

    /**
     * @var array<string, list<array{name: string, logo: string|null}>>|null
     */
    private static ?array $catalogue = null;

    /**
     * The page copy with the catalogue's own figures substituted in, so the
     * counts in the prose cannot disagree with the list underneath them.
     *
     * Only :count and :countries are filled here. The filter's own counter
     * changes per keystroke, so it keeps a :matches placeholder the browser
     * fills — deliberately a different name, because this substitution walks
     * every string and would otherwise bake the total into it.
     *
     * @return array<string, mixed>
     */
    public static function content(string $locale): array
    {
        $groups = self::countries($locale);

        $countries = array_filter($groups, fn (array $group): bool => $group['code'] !== MarketingContent::WORLDWIDE);

        $replacements = [
            ':count' => self::formatCount(array_sum(array_map(fn (array $group): int => count($group['entries']), $groups)), $locale),
            ':countries' => (string) count($countries),
        ];

        return array_map(
            fn (mixed $value): mixed => is_string($value) ? strtr($value, $replacements) : $value,
            MarketingContent::integrations($locale),
        );
    }

    /**
     * Everything connectable, grouped by country and named in this language:
     * the bank catalogue plus the API-key apps folded into the group they
     * belong to. A visitor searching for Coinbase is asking the same question
     * as one searching for BBVA, so they share one list.
     *
     * The worldwide group comes first because it applies to every visitor,
     * whichever country they bank in.
     *
     * @return list<array{code: string, name: string, entries: list<array{name: string, logo: string|null, key: bool}>}>
     */
    public static function countries(string $locale): array
    {
        $catalogue = self::catalogue();
        $apps = self::appsByCountry();

        $codes = [MarketingContent::WORLDWIDE, ...array_keys(MarketingContent::COUNTRIES)];
        $groups = [];

        foreach ($codes as $code) {
            $entries = [
                ...($apps[$code] ?? []),
                ...array_map(fn (array $bank): array => [
                    'name' => $bank['name'],
                    'logo' => $bank['logo'] ?? null,
                    'key' => false,
                ], $catalogue[$code] ?? []),
            ];

            if ($entries === []) {
                continue;
            }

            $groups[] = ['code' => $code, 'name' => self::countryName($code, $locale), 'entries' => $entries];
        }

        return $groups;
    }

    /**
     * The API-key apps grouped by the country code they belong to. Indexa
     * Capital is Spain-only in the connect flow, so it sits with the Spanish
     * banks rather than in the worldwide group.
     *
     * @return array<string, list<array{name: string, logo: string|null, key: bool}>>
     */
    private static function appsByCountry(): array
    {
        $apps = [];

        foreach (MarketingContent::API_PROVIDERS as $name => $app) {
            $apps[$app['country']][] = ['name' => $name, 'logo' => $app['logo'], 'key' => true];
        }

        return $apps;
    }

    /**
     * Name of a country group, the worldwide one included.
     */
    private static function countryName(string $code, string $locale): string
    {
        $names = $code === MarketingContent::WORLDWIDE
            ? MarketingContent::WORLDWIDE_NAMES
            : (MarketingContent::COUNTRIES[$code] ?? MarketingContent::WORLDWIDE_NAMES);

        return $names[$locale] ?? $names['en'];
    }

    /**
     * The shared chrome labels this page actually uses. The comparison set is
     * not sent whole: most of it is section headings for a table this page does
     * not have, and one of them still carries an unresolved :rival.
     *
     * @return array<string, string>
     */
    public static function labels(string $locale): array
    {
        return array_intersect_key(
            MarketingContent::labels($locale),
            array_flip(['cta', 'pricing', 'back', 'other_language']),
        );
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
            'heading' => self::content($locale)['heading'],
            'path' => '/'.self::path($locale),
            'url' => self::url($locale),
        ];
    }

    /**
     * A count with the thousands separator the language uses, so a Spanish
     * reader is not shown "2,223" for two thousand.
     *
     * @api Consumed by the test suite, which static analysis does not scan.
     */
    public static function formatCount(int $count, string $locale): string
    {
        return $locale === 'en'
            ? number_format($count)
            : number_format($count, 0, ',', '.');
    }

    /**
     * How many banks the committed catalogue holds per country, for the sync
     * command to compare a fresh fetch against.
     *
     * @return array<string, int>
     */
    public static function committedCounts(): array
    {
        return array_map('count', self::catalogue());
    }

    /**
     * The committed catalogue, keyed by country code. A missing file leaves the
     * page standing with no bank list rather than 500ing on a marketing URL.
     *
     * @return array<string, list<array{name: string, logo: string|null}>>
     */
    private static function catalogue(): array
    {
        if (self::$catalogue !== null) {
            return self::$catalogue;
        }

        $path = base_path(self::CATALOGUE);

        if (! is_file($path)) {
            return self::$catalogue = [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return self::$catalogue = is_array($decoded['countries'] ?? null) ? $decoded['countries'] : [];
    }
}
