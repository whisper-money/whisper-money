<?php

use App\Models\User;
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

test('the banktrack page is published in english and in spanish', function (string $path, string $locale) {
    $this->get($path)
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('comparison')
            ->where('pageLocale', $locale)
            ->where('page.slug', 'banktrack-vs-whisper-money')
            ->where('page.rival', 'Banktrack')
            ->has('page.migration_steps', 5)
        );

    $this->get($path.'.md')
        ->assertSuccessful()
        ->assertSee('# Banktrack vs Whisper Money', false);
})->with([
    ['/compare/banktrack-vs-whisper-money', 'en'],
    ['/comparativa/banktrack-vs-whisper-money', 'es'],
]);

test('the banktrack page is in the sitemap with its spanish alternate', function () {
    $content = $this->get('/sitemap.xml')->assertSuccessful()->content();
    $english = ComparisonPages::url('en', 'banktrack-vs-whisper-money');
    $spanish = ComparisonPages::url('es', 'banktrack-vs-whisper-money');

    expect($english)->toEndWith('/compare/banktrack-vs-whisper-money')
        ->and($spanish)->toEndWith('/comparativa/banktrack-vs-whisper-money')
        ->and($content)->toContain("<loc>{$english}</loc>")
        ->and($content)->toContain("<loc>{$spanish}</loc>")
        ->and($content)->toContain('hreflang="es" href="'.$spanish.'"');
});

test('the landing links to the banktrack page in every language', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('welcome')
            ->where('comparisonLinks', fn ($links): bool => collect($links)->contains(
                fn (array $link): bool => $link['key'] === 'banktrack'
                    && $link['path'] === '/compare/banktrack-vs-whisper-money'
                    && $link['heading'] === 'Banktrack vs Whisper Money'
            ))
        );

    expect(collect(ComparisonPages::index('es'))->firstWhere('key', 'banktrack'))
        ->toMatchArray([
            'path' => '/comparativa/banktrack-vs-whisper-money',
            'heading' => 'Banktrack vs Whisper Money',
        ]);
});

test('the agent summary mentions the full import from another app', function () {
    expect($this->get('/index.md')->assertSuccessful()->content())->toContain('Brings in a whole export from another finance app')
        ->and($this->get('/index.es.md')->assertSuccessful()->content())->toContain('la exportación entera de otra app de finanzas');
});

test('the copy about the full import window matches the window', function () {
    // The docs and the comparison pages say "15 days" in prose. If the window
    // changes, so must they: resources/docs/documentation/20-your-data and
    // MarketingContent.
    expect(User::FULL_IMPORT_WINDOW_DAYS)->toBe(15, 'The full import window changed: update the "15 days" in its docs and comparison pages');
});
