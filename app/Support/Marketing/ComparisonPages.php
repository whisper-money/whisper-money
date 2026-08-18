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
     * landing footer, the sitemap and llms.txt.
     *
     * @return list<array{key: string, slug: string, heading: string, path: string, url: string}>
     */
    public static function index(string $locale): array
    {
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
     * The language to link the comparison pages in for a visitor whose locale
     * may not be one they are published in.
     */
    public static function linkLocale(string $locale): string
    {
        return in_array($locale, MarketingContent::LOCALES, true) ? $locale : 'en';
    }

    /**
     * Testimonials with the quote resolved to one language.
     *
     * Slots still waiting for a real quote are dropped outside local
     * development, so their marker never reaches a served page at all — not even
     * as unrendered text in the page's own data, where a crawler would read it.
     *
     * @param  array<string, mixed>  $page
     * @return list<array<string, string>>
     */
    private static function testimonials(array $page, string $locale): array
    {
        $testimonials = array_filter(
            $page['testimonials'],
            fn (array $testimonial): bool => ! isset($testimonial['pending']) || app()->isLocal(),
        );

        return array_values(array_map(
            fn (array $testimonial): array => isset($testimonial['pending'])
                ? $testimonial
                : [...$testimonial, 'text' => $testimonial['text'][$locale]],
            $testimonials,
        ));
    }
}
