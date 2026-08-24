<?php

namespace App\Http\Controllers;

use App\Support\Documentation\DocumentationTree;
use App\Support\Marketing\ComparisonPages;
use App\Support\Marketing\IntegrationsPage;
use App\Support\Marketing\MarketingContent;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function index(): Response
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        $urls = [
            ['loc' => $baseUrl, 'changefreq' => 'weekly', 'priority' => '1.0'],
            ['loc' => "{$baseUrl}/roadmap", 'changefreq' => 'daily', 'priority' => '0.7'],
            ['loc' => "{$baseUrl}/privacy", 'changefreq' => 'monthly', 'priority' => '0.5'],
            ['loc' => "{$baseUrl}/terms", 'changefreq' => 'monthly', 'priority' => '0.5'],
            ['loc' => "{$baseUrl}/llms.txt", 'changefreq' => 'weekly', 'priority' => '0.3'],
            ...$this->integrationUrls(),
            ...$this->comparisonUrls(),
            ...$this->documentationUrls(),
        ];

        return response($this->render($urls), 200)
            ->header('Content-Type', 'application/xml');
    }

    /**
     * The integrations page in every language, each carrying the others as
     * alternates. The Markdown twins stay out: they are non-canonical URLs.
     *
     * @return list<array<string, mixed>>
     */
    private function integrationUrls(): array
    {
        $alternates = IntegrationsPage::alternates();

        return array_values(array_map(fn (string $url): array => [
            'loc' => $url,
            'changefreq' => 'monthly',
            'priority' => '0.8',
            'alternates' => $alternates,
        ], $alternates));
    }

    /**
     * Every comparison page in every language it is published in. Each entry
     * carries the alternates for the others, so a search engine treats them as
     * one page in two languages rather than as two competing pages.
     *
     * @return list<array<string, mixed>>
     */
    private function comparisonUrls(): array
    {
        $urls = [];

        foreach (MarketingContent::LOCALES as $locale) {
            foreach (ComparisonPages::index($locale) as $page) {
                $urls[] = [
                    'loc' => $page['url'],
                    'changefreq' => 'monthly',
                    'priority' => '0.8',
                    'alternates' => ComparisonPages::alternates($page['key']),
                ];
            }
        }

        return $urls;
    }

    /**
     * Every documentation page in every language it is published in. The tree
     * comes from the files, so a page added to the documentation is crawled
     * without anyone remembering to list it here.
     *
     * @return list<array<string, mixed>>
     */
    private function documentationUrls(): array
    {
        $locales = array_keys((array) config('documentation.locales', []));
        $urls = [];

        foreach ($locales as $locale) {
            foreach (DocumentationTree::for((string) $locale)->pages() as $page) {
                $urls[] = [
                    'loc' => route('documentation.show', ['slug' => $page['slug'], 'lang' => $locale]),
                    'changefreq' => 'monthly',
                    'priority' => '0.6',
                    'alternates' => collect($locales)
                        ->mapWithKeys(fn (mixed $alternate): array => [
                            (string) $alternate => route('documentation.show', ['slug' => $page['slug'], 'lang' => $alternate]),
                        ])
                        ->all(),
                ];
            }
        }

        return $urls;
    }

    /**
     * @param  list<array<string, mixed>>  $urls
     */
    private function render(array $urls): string
    {
        $lastmod = now()->toW3cString();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";

        foreach ($urls as $url) {
            $xml .= '  <url>'."\n";
            $xml .= "    <loc>{$url['loc']}</loc>\n";
            $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
            $xml .= "    <changefreq>{$url['changefreq']}</changefreq>\n";
            $xml .= "    <priority>{$url['priority']}</priority>\n";

            foreach ($url['alternates'] ?? [] as $locale => $href) {
                $xml .= '    <xhtml:link rel="alternate" hreflang="'.$locale.'" href="'.$href.'"/>'."\n";
            }

            $xml .= '  </url>'."\n";
        }

        return $xml.'</urlset>';
    }
}
