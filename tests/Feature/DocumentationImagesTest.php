<?php

use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

/**
 * Screenshots go stale, get renamed, and get half-committed. These tests fail
 * when a page points at a file that is not there, which is the failure mode that
 * would otherwise reach production as a broken image in one theme only.
 *
 * A page embeds a screen recording with the same `![...](...)` syntax, so these
 * cover both; what each one needs of its files differs, and is split below.
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

function documentationVideos(): array
{
    return array_values(array_filter(
        documentationImages(),
        fn (array $embed): bool => str_ends_with($embed['url'], '.mp4') || str_ends_with($embed['url'], '.webm'),
    ));
}

function documentationScreenshots(): array
{
    return array_values(array_filter(
        documentationImages(),
        fn (array $embed): bool => ! in_array($embed, documentationVideos(), true),
    ));
}

it('has a light and a dark copy of every screenshot a page points at', function () {
    $images = documentationScreenshots();

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

it('has the file and the poster of every recording a page points at', function () {
    $videos = documentationVideos();

    expect($videos)->not->toBeEmpty();

    foreach ($videos as $video) {
        $url = $video['url'];

        expect($url)->toStartWith('/docs/documentation/', "{$video['page']} points at {$url}, which is not under /docs/documentation/");
        expect(File::exists(public_path(ltrim($url, '/'))))->toBeTrue("{$video['page']} points at {$url}, which does not exist");

        // Without it the page shows a black rectangle until it is played.
        $poster = (string) preg_replace('/\.[a-z0-9]+$/i', '-poster.jpg', $url);

        expect(File::exists(public_path(ltrim($poster, '/'))))->toBeTrue("{$url} has no poster at {$poster}");
    }
});

it('renders a recording as a video with its poster, not as an image', function () {
    $this->get(route('documentation.show', ['slug' => 'savings-goals', 'lang' => 'en']))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('document.html', fn (string $html): bool => str_contains($html, '<figure class="documentation-video">')
                    && str_contains($html, '<video src="/docs/documentation/savings-goal-create.mp4"')
                    && str_contains($html, 'poster="/docs/documentation/savings-goal-create-poster.jpg"')
                    && ! str_contains($html, '<img src="/docs/documentation/savings-goal-create.mp4"'))
        );
});
