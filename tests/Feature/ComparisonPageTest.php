<?php

use App\Support\Marketing\ComparisonPages;
use App\Support\Marketing\MarketingContent;
use Inertia\Testing\AssertableInertia;

/**
 * @return list<array{string, string}>
 */
function comparisonRoutes(): array
{
    $routes = [];

    foreach (MarketingContent::LOCALES as $locale) {
        foreach (ComparisonPages::slugs($locale) as $slug) {
            $routes[] = [$locale, $slug];
        }
    }

    return $routes;
}

test('every comparison page renders in its own language', function (string $locale, string $slug) {
    $this->get('/'.ComparisonPages::path($locale, $slug))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('comparison')
            ->where('pageLocale', $locale)
            ->where('page.slug', $slug)
            ->has('page.rows', 4)
            ->has('labels')
        );
})->with(comparisonRoutes());

test('each page links every language with hreflang and a canonical', function (string $locale, string $slug) {
    $this->get('/'.ComparisonPages::path($locale, $slug))
        ->assertSuccessful()
        ->assertInertia(function (AssertableInertia $page) use ($locale, $slug) {
            $alternates = $page->toArray()['props']['alternates'];

            expect(array_keys($alternates))->toEqualCanonicalizing(MarketingContent::LOCALES)
                ->and($alternates[$locale])->toBe(ComparisonPages::url($locale, $slug));
        });
})->with(comparisonRoutes());

test('every comparison page has a markdown twin canonicalised to the html page', function (string $locale, string $slug) {
    $response = $this->get('/'.ComparisonPages::path($locale, $slug).'.md')->assertSuccessful();

    expect($response->headers->get('Content-Type'))->toContain('text/markdown')
        ->and($response->headers->get('Link'))->toBe('<'.ComparisonPages::url($locale, $slug).'>; rel="canonical"')
        ->and($response->content())->toStartWith('# ')
        ->and($response->content())->toContain('| --- | --- | --- |');
})->with(comparisonRoutes());

test('each page declares canonical and hreflang as http headers too', function (string $locale, string $slug) {
    $link = $this->get('/'.ComparisonPages::path($locale, $slug))
        ->assertSuccessful()
        ->headers->get('Link');

    $canonical = ComparisonPages::url($locale, $slug);

    expect($link)->toContain('<'.$canonical.'>; rel="canonical"')
        ->and($link)->toContain('rel="alternate"; hreflang="x-default"')
        ->and($link)->toContain('<'.$canonical.'.md>; rel="alternate"; type="text/markdown"');

    foreach (ComparisonPages::alternates(ComparisonPages::find($locale, $slug)['key']) as $alternateLocale => $url) {
        expect($link)->toContain('<'.$url.'>; rel="alternate"; hreflang="'.$alternateLocale.'"');
    }
})->with(comparisonRoutes());

test('an unknown comparison slug is not found in either language', function (string $path) {
    $this->get($path)->assertNotFound();
})->with([
    ['/compare/whatever-alternative'],
    ['/comparativa/alternativa-a-lo-que-sea'],
    ['/compare/whatever-alternative.md'],
]);

test('a slug from one language does not resolve under the other', function () {
    $this->get('/compare/alternativa-a-fintonic')->assertNotFound();
    $this->get('/comparativa/fintonic-alternative')->assertNotFound();
});

test('the spanish and english pages are distinct translations of each other', function () {
    $english = ComparisonPages::find('en', 'fintonic-alternative');
    $spanish = ComparisonPages::find('es', 'alternativa-a-fintonic');

    expect($english['key'])->toBe($spanish['key'])
        ->and($english['heading'])->not->toBe($spanish['heading'])
        ->and($english['rows'])->toHaveCount(count($spanish['rows']));
});

test('every page carries real testimonials and no placeholders', function () {
    foreach (MarketingContent::comparisonPages() as $key => $page) {
        expect($page['testimonials'])->toHaveCount(2, "{$key} should quote two real users");

        foreach ($page['testimonials'] as $testimonial) {
            expect($testimonial)->toHaveKeys(['name', 'text'])
                ->and($testimonial)->not->toHaveKey('pending')
                ->and($testimonial['name'])->not->toBeEmpty();

            foreach (MarketingContent::LOCALES as $locale) {
                expect($testimonial['text'][$locale] ?? '')->not->toBeEmpty(
                    "{$key}: the quote from {$testimonial['name']} is missing its {$locale} text"
                );
            }
        }
    }
});

test('a served page renders every testimonial it has', function (string $locale, string $slug) {
    $path = '/'.ComparisonPages::path($locale, $slug);
    $markdown = $this->get($path.'.md')->assertSuccessful()->content();

    foreach (ComparisonPages::find($locale, $slug)['testimonials'] as $testimonial) {
        expect($markdown)->toContain($testimonial['name'])
            ->and($markdown)->toContain($testimonial['text']);
    }
})->with(comparisonRoutes());

test('a testimonial quote is served in the language of the page', function () {
    expect(ComparisonPages::find('en', 'fintonic-alternative')['testimonials'][0]['text'])
        ->toContain('Thank you for developing Whisper Money')
        ->and(ComparisonPages::find('es', 'alternativa-a-fintonic')['testimonials'][0]['text'])
        ->toContain('Gracias por desarrollar Whisper Money');
});

test('the landing markdown summary is served for every language', function (string $locale) {
    $path = $locale === 'en' ? '/index.md' : "/index.{$locale}.md";
    $response = $this->get($path)->assertSuccessful();

    expect($response->headers->get('Content-Type'))->toContain('text/markdown')
        ->and($response->content())->toContain('# Whisper Money')
        ->and($response->content())->toContain(ComparisonPages::url($locale, ComparisonPages::slugs($locale)[0]));
})->with(MarketingContent::LOCALES);

test('llms txt lists every page in every language', function () {
    $response = $this->get('/llms.txt')->assertSuccessful();

    expect($response->headers->get('Content-Type'))->toContain('text/plain');

    foreach (MarketingContent::LOCALES as $locale) {
        foreach (ComparisonPages::slugs($locale) as $slug) {
            expect($response->content())->toContain(ComparisonPages::url($locale, $slug));
        }
    }

    expect($response->content())->toContain(config('app.url').'/index.md');
});

test('the sitemap lists every language of every comparison page with its alternates', function () {
    $content = $this->get('/sitemap.xml')->assertSuccessful()->content();

    foreach (MarketingContent::LOCALES as $locale) {
        foreach (ComparisonPages::slugs($locale) as $slug) {
            $url = ComparisonPages::url($locale, $slug);

            expect($content)->toContain("<loc>{$url}</loc>")
                ->and($content)->toContain('hreflang="'.$locale.'" href="'.$url.'"');
        }
    }

    expect($content)->toContain('<loc>'.config('app.url').'/llms.txt</loc>');
});

test('pinning the page language leaves the visitor session locale alone', function () {
    $this->withSession(['locale' => 'es'])
        ->get('/compare/fintonic-alternative')
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('pageLocale', 'en'));

    expect(session('locale'))->toBe('es');
});
