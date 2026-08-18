<?php

namespace App\Http\Controllers;

use App\Support\Marketing\ComparisonPages;
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
            ...$this->comparisonUrls(),
        ];

        return response($this->render($urls), 200)
            ->header('Content-Type', 'application/xml');
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
