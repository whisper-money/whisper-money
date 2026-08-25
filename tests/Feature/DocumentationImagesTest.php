<?php

use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

/**
 * Screenshots go stale, get renamed, and get half-committed. These tests fail
 * when a page points at a file that is not there, which is the failure mode that
 * would otherwise reach production as a broken image in one theme only.
 *
 * @return list<array{page: string, alt: string, url: string}>
 */
function documentationImages(): array
{
    $images = [];

    foreach (File::allFiles((string) config('documentation.root')) as $file) {
        if ($file->getExtension() !== 'md') {
            continue;
        }

        preg_match_all('/!\[([^\]]*)\]\(([^)\s]+)\)/', $file->getContents(), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $images[] = ['page' => $file->getFilename(), 'alt' => $match[1], 'url' => $match[2]];
        }
    }

    return $images;
}

it('has a light and a dark copy of every screenshot a page points at', function () {
    $images = documentationImages();

    expect($images)->not->toBeEmpty();

    foreach ($images as $image) {
        $light = $image['url'];

        expect($light)->toStartWith('/docs/documentation/', "{$image['page']} points at {$light}, which is not under /docs/documentation/");

        $dark = (string) preg_replace('/(\.[a-z0-9]+)$/i', '-dark$1', $light);

        expect(File::exists(public_path(ltrim($light, '/'))))->toBeTrue("{$image['page']} points at {$light}, which does not exist");
        expect(File::exists(public_path(ltrim($dark, '/'))))->toBeTrue("{$light} has no dark copy at {$dark}");
    }
});

it('describes every screenshot, because the alt text is all an agent gets', function () {
    foreach (documentationImages() as $image) {
        expect(str_word_count($image['alt']))->toBeGreaterThan(3, "{$image['page']} has a screenshot with too short an alt: \"{$image['alt']}\"");
    }
});

it('renders a screenshot as a light and a dark image, one of which the theme hides', function () {
    $this->get(route('documentation.show', ['slug' => 'transactions', 'lang' => 'en']))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('document.html', fn (string $html): bool => str_contains($html, '/docs/documentation/transaction-filters.png" alt="The transaction filter bar')
                    && str_contains($html, 'class="documentation-shot documentation-shot--light"')
                    && str_contains($html, '/docs/documentation/transaction-filters-dark.png')
                    && str_contains($html, 'class="documentation-shot documentation-shot--dark"'))
        );
});

it('hands agents an absolute url for a screenshot, so the page stands on its own', function () {
    expect($this->get(route('documentation.markdown', ['slug' => 'transactions', 'lang' => 'en']))->assertOk()->getContent())
        ->toContain(']('.url('/docs/documentation/transaction-filters.png').')');
});

it('shows the light copy in both themes when the dark one has not been taken', function () {
    config(['documentation.root' => base_path('tests/.documentation-images')]);

    File::ensureDirectoryExists(base_path('tests/.documentation-images'));
    File::put(
        base_path('tests/.documentation-images/only-light-en.md'),
        "# Only light\n\nA page whose screenshot has no dark copy.\n\n![A screenshot with no dark copy taken yet](/docs/documentation/does-not-exist.png)\n",
    );

    $this->get(route('documentation.show', ['slug' => 'only-light', 'lang' => 'en']))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('document.html', fn (string $html): bool => substr_count($html, '/docs/documentation/does-not-exist.png') === 2
                    && ! str_contains($html, 'does-not-exist-dark.png'))
        );

    File::deleteDirectory(base_path('tests/.documentation-images'));
});
