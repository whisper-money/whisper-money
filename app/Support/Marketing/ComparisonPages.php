<?php

namespace App\Support\Marketing;

/**
 * Lookup and URL construction for the comparison landings.
 *
 * Each language gets its own URL rather than one URL that swaps content on the
 * visitor's session locale, because a search engine can only index a language it
 * can reach at a stable address. The two are then tied together with hreflang.
 */
final class ComparisonPages
{
    /**
     * The page in the given language, or null when the slug is not one of ours.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $locale, string $slug): ?array
    {
        foreach (MarketingContent::comparisonPages() as $key => $page) {
            if (($page[$locale]['slug'] ?? null) === $slug) {
                return [...$page[$locale], 'key' => $key, 'testimonials' => self::testimonials($page, $locale)];
            }
        }

        return null;
    }

    /**
     * Slugs published in the given language, for route constraints and tests.
     *
     * @api Consumed by routes/web.php, which static analysis does not scan.
     *
     * @return list<string>
     */
    public static function slugs(string $locale): array
    {
        return array_values(array_map(
            fn (array $page): string => $page[$locale]['slug'],
            MarketingContent::comparisonPages(),
        ));
    }

    /**
     * Path for a page in a language, without a leading slash.
     *
     * @api Consumed by the test suite, which static analysis does not scan.
     */
    public static function path(string $locale, string $slug): string
    {
        return MarketingContent::BASE_PATHS[$locale].'/'.$slug;
    }

    /**
     * Absolute URL for a page in a language.
     */
    public static function url(string $locale, string $slug): string
    {
        return rtrim((string) config('app.url'), '/').'/'.self::path($locale, $slug);
    }

    /**
     * Every language this page exists in, keyed by locale, for hreflang and for
     * the in-page language switch.
     *
     * @return array<string, string>
     */
    public static function alternates(string $key): array
    {
        $page = MarketingContent::comparisonPages()[$key] ?? null;

        if ($page === null) {
            return [];
        }

        $alternates = [];

        foreach (MarketingContent::LOCALES as $locale) {
            $alternates[$locale] = self::url($locale, $page[$locale]['slug']);
        }

        return $alternates;
    }

    /**
     * Every published page in a language as title and URL pairs, for the
     * landing footer, the sitemap and llms.txt. A locale these pages are not
     * published in falls back to English rather than returning nothing.
     *
     * @return list<array{key: string, slug: string, heading: string, path: string, url: string}>
     */
    public static function index(string $locale): array
    {
        $locale = in_array($locale, MarketingContent::LOCALES, true) ? $locale : 'en';
        $index = [];

        foreach (MarketingContent::comparisonPages() as $key => $page) {
            $slug = $page[$locale]['slug'];

            $index[] = [
                'key' => $key,
                'slug' => $slug,
                'heading' => $page[$locale]['heading'],
                'path' => '/'.self::path($locale, $slug),
                'url' => self::url($locale, $slug),
            ];
        }

        return $index;
    }

    /**
     * Testimonials with the quote resolved to one language. Every one is a real
     * quote from a real user; there is no placeholder path.
     *
     * @param  array<string, mixed>  $page
     * @return list<array<string, string>>
     */
    private static function testimonials(array $page, string $locale): array
    {
        return array_map(
            fn (array $testimonial): array => [...$testimonial, 'text' => $testimonial['text'][$locale]],
            $page['testimonials'],
        );
    }
}
