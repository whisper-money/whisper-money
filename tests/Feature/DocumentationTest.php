<?php

use App\Support\Documentation\DocumentationTree;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

/**
 * @return array<int, string>
 */
function publishedSlugs(): array
{
    return array_column(DocumentationTree::for('en')->pages(), 'slug');
}

it('shows the default documentation page', function () {
    $this->get(route('documentation.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('documentation/show')
                ->where('document.slug', 'getting-started')
                ->where('document.locale', 'en')
                ->where('document.title', 'Getting started')
                ->where('document.markdownUrl', '/documentation/getting-started.md?lang=en')
                ->where('languages.0.active', true)
                ->where('languages.1.url', '/documentation/getting-started?lang=es')
        );
});

it('shows every published page in every locale', function () {
    foreach (publishedSlugs() as $slug) {
        foreach (array_keys((array) config('documentation.locales')) as $locale) {
            $this->get(route('documentation.show', ['slug' => $slug, 'lang' => $locale]))
                ->assertOk()
                ->assertInertia(
                    fn (AssertableInertia $page) => $page
                        ->component('documentation/show')
                        ->where('document.slug', $slug)
                        ->where('document.locale', $locale)
                );
        }
    }
});

it('sends the sidebar as sections of pages, with the branch being read expanded', function () {
    $this->get(route('documentation.show', ['slug' => 'accounts', 'lang' => 'es']))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('navigation.0.type', 'page')
                ->where('navigation.0.title', 'Primeros pasos')
                ->where('navigation.0.active', false)
                ->where('navigation.1.type', 'section')
                ->where('navigation.1.title', 'Tus datos')
                ->where('navigation.1.expanded', true)
                ->where('navigation.1.children.0.slug', 'accounts')
                ->where('navigation.1.children.0.active', true)
                ->where('navigation.1.children.0.url', '/documentation/accounts?lang=es')
                ->where('navigation.1.children.1.active', false)
                ->where('navigation.2.expanded', false)
        );
});

it('sends a search index of every page and every heading', function () {
    $this->get(route('documentation.show', ['slug' => 'accounts', 'lang' => 'es']))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('searchIndex', count(publishedSlugs()))
                ->where('searchIndex.0.slug', 'getting-started')
                ->where('searchIndex.0.url', '/documentation/getting-started?lang=es')
                ->where('searchIndex.0.headings.0.title', 'Inicio rápido')
                ->where('searchIndex.0.headings.0.id', 'inicio-rapido')
        );
});

it('links each page to the pages either side of it, reading through its children', function () {
    $this->get(route('documentation.show', ['slug' => 'accounts', 'lang' => 'en']))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('neighbours.previous.title', 'Getting started')
                ->where('neighbours.next.title', 'Import balances')
                ->where('neighbours.next.url', '/documentation/accounts/import-balances?lang=en')
        );

    // The last child of a page leads on to the next page, not back up.
    $this->get(route('documentation.show', ['slug' => 'accounts/edit-balances', 'lang' => 'en']))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('neighbours.previous.title', 'Import balances')
                ->where('neighbours.next.title', 'Transactions')
        );

    $this->get(route('documentation.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('neighbours.previous', null));
});

it('sends the contents of the page as numbered headings rather than as markup', function () {
    $this->get(route('documentation.show', ['slug' => 'categories', 'lang' => 'en']))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('document.headings.1.title', 'Category map')
                ->where('document.headings.1.id', 'category-map')
                ->where('document.headings.1.level', 2)
                ->where('document.headings.1.number', '2')
                ->where('document.html', fn (string $html): bool => ! str_contains($html, '{{TOC}}')
                    && ! str_contains($html, 'documentation-toc'))
        );
});

it('uses the configured heading levels for the contents', function () {
    config(['documentation.toc.levels' => [2]]);

    $this->get(route('documentation.show', ['slug' => 'categories', 'lang' => 'en']))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('document.headings', fn (Collection $headings): bool => $headings
                    ->every(fn (array $heading): bool => $heading['level'] === 2))
                ->where('document.html', fn (string $html): bool => str_contains($html, '<h2 id="category-map">')
                    && ! str_contains($html, '<h3 id="expense">'))
        );
});

it('renders headings, cards and diagrams in the page html', function () {
    $this->get(route('documentation.show', ['slug' => 'categories', 'lang' => 'en']))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('document.html', fn (string $html): bool => str_contains($html, '<h2 id="category-map">')
                    && str_contains($html, '<h3 id="expense">')
                    && str_contains($html, '<div class="documentation-diagram">')
                    && str_contains($html, '<svg viewBox="0 0 720 272"')
                    && str_contains($html, '<title id="category-fanout-en-title">What a category is for</title>')
                    && ! str_contains($html, 'language-mermaid')
                    && str_contains($html, '<div class="cards-wrapper">')
                    && str_contains($html, '<section class="card">')
                    && ! str_contains($html, '<div class="card">'))
        );
});

it('points at the markdown twin of the page', function () {
    $this->get(route('documentation.show', ['slug' => 'accounts', 'lang' => 'es']))
        ->assertOk()
        ->assertHeader('Link', implode(', ', [
            '<'.route('documentation.show', ['slug' => 'accounts', 'lang' => 'es']).'>; rel="canonical"',
            '<'.route('documentation.show', ['slug' => 'accounts', 'lang' => 'en']).'>; rel="alternate"; hreflang="en"',
            '<'.route('documentation.show', ['slug' => 'accounts', 'lang' => 'es']).'>; rel="alternate"; hreflang="es"',
            '<'.route('documentation.show', ['slug' => 'accounts', 'lang' => 'en']).'>; rel="alternate"; hreflang="x-default"',
            '<'.route('documentation.markdown', ['slug' => 'accounts', 'lang' => 'es']).'>; rel="alternate"; type="text/markdown"',
        ]));
});

it('serves every page as markdown for agents', function () {
    $response = $this->get(route('documentation.markdown', ['slug' => 'accounts', 'lang' => 'es']));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
        ->assertHeader('Link', '<'.route('documentation.show', ['slug' => 'accounts', 'lang' => 'es']).'>; rel="canonical"');

    $body = $response->getContent();

    expect($body)->toStartWith('# Cuentas')
        ->and($body)->toContain('## Tipos de cuenta')
        ->and($body)->toContain('```mermaid')
        ->and($body)->not->toContain('%% diagram:')
        ->and($body)->not->toContain('{{TOC}}')
        ->and($body)->not->toContain('<div class="cards-wrapper">')
        ->and($body)->not->toContain('<div class="card">');
});

it('serves an index of every page at /documentation.md, rather than the first page', function () {
    $body = $this->get('/documentation.md')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
        ->getContent();

    expect($body)->toStartWith('# Whisper Money documentation')
        ->and($body)->not->toContain('# Getting started');

    foreach (publishedSlugs() as $slug) {
        expect($body)->toContain(route('documentation.markdown', ['slug' => $slug, 'lang' => 'en']));
    }
});

it('indexes the pages under the section that groups them, nested under their parent', function () {
    $body = $this->get('/documentation.md')->assertOk()->getContent();

    expect($body)->toContain('## Your data')
        ->and($body)->toContain('- [Transactions](')
        ->and($body)->toContain('  - [Import transactions](');
});

it('writes the index in the language asked for', function () {
    $body = $this->get(route('documentation.index.markdown', ['lang' => 'es']))->assertOk()->getContent();

    expect($body)->toStartWith('# Documentación de Whisper Money')
        ->and($body)->toContain('## Tus datos')
        ->and($body)->toContain(route('documentation.markdown', ['slug' => 'transactions', 'lang' => 'es']));
});

it('ends every page for agents with its own children and a link to the index', function () {
    $transactions = $this->get(route('documentation.markdown', ['slug' => 'transactions', 'lang' => 'en']))
        ->assertOk()
        ->getContent();

    expect($transactions)->toContain('Pages inside this one:')
        ->and($transactions)->toContain('- [Create a transaction]('.route('documentation.markdown', ['slug' => 'transactions/create', 'lang' => 'en']).')')
        ->and($transactions)->toContain('Full documentation index: '.route('documentation.index.markdown', ['lang' => 'en']));

    $leaf = $this->get(route('documentation.markdown', ['slug' => 'labels', 'lang' => 'es']))
        ->assertOk()
        ->getContent();

    expect($leaf)->not->toContain('Páginas dentro de esta:')
        ->and($leaf)->toContain('Índice completo de la documentación: '.route('documentation.index.markdown', ['lang' => 'es']));
});

it('serves a page nested under another page at a path of its own', function () {
    $this->get(route('documentation.show', ['slug' => 'transactions/import', 'lang' => 'en']))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('document.slug', 'transactions/import')
                ->where('document.title', 'Import transactions')
                ->where('navigation.1.children.1.children.1.slug', 'transactions/import')
        );

    $this->get('/documentation/transactions/import.md')
        ->assertOk()
        ->assertSee('# Import transactions', false);
});

it('redirects a page that has moved under another page, in html and in markdown', function () {
    $this->get('/documentation/imports')
        ->assertMovedPermanently()
        ->assertRedirect(route('documentation.show', ['slug' => 'transactions/import', 'lang' => 'en']));

    $this->get('/documentation/imports.md')
        ->assertMovedPermanently()
        ->assertRedirect(route('documentation.markdown', ['slug' => 'transactions/import', 'lang' => 'en']));

    $this->get(route('documentation.show', ['slug' => 'imports', 'lang' => 'es']))
        ->assertMovedPermanently()
        ->assertRedirect(route('documentation.show', ['slug' => 'transactions/import', 'lang' => 'es']));
});

it('points llms.txt at the index as well as at each page', function () {
    expect($this->get(route('llms'))->assertOk()->getContent())
        ->toContain(route('documentation.index.markdown', ['lang' => 'en']))
        ->toContain(route('documentation.index.markdown', ['lang' => 'es']));
});

it('lists the documentation in llms.txt', function () {
    $body = $this->get(route('llms'))->assertOk()->getContent();

    expect($body)->toContain('## Documentation (English)')
        ->and($body)->toContain('## Documentación (español)')
        ->and($body)->toContain(route('documentation.markdown', ['slug' => 'accounts', 'lang' => 'en']));
});

it('lists every documentation page in the sitemap, with its other languages', function () {
    $body = $this->get('/sitemap.xml')->assertOk()->getContent();

    foreach (publishedSlugs() as $slug) {
        expect($body)->toContain('<loc>'.route('documentation.show', ['slug' => $slug, 'lang' => 'en']).'</loc>');
    }

    expect($body)->toContain('hreflang="es" href="'.route('documentation.show', ['slug' => 'accounts', 'lang' => 'es']).'"');
});

it('returns not found for unknown pages, in html and in markdown', function () {
    $this->get('/documentation/unknown')->assertNotFound();
    $this->get('/documentation/unknown.md')->assertNotFound();
});

it('draws every mermaid block in the documentation as inline svg', function () {
    $pages = File::allFiles(resource_path('docs/documentation'));
    $markers = 0;

    foreach ($pages as $page) {
        $markdown = File::get($page->getPathname());

        if (! str_contains($markdown, '```mermaid')) {
            continue;
        }

        preg_match_all('/^\s*%% diagram: ([a-z0-9-]+)$/m', $markdown, $matches);

        expect($matches[1])
            ->toHaveCount(substr_count($markdown, '```mermaid'), "{$page->getRelativePathname()} has a mermaid block without a diagram marker");

        foreach ($matches[1] as $diagram) {
            expect(resource_path("docs/diagrams/{$diagram}.svg"))->toBeFile();
            $markers++;
        }
    }

    expect($markers)->toBeGreaterThan(0);
});
