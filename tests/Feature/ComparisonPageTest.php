<?php

use App\Http\Controllers\ComparisonController;
use Inertia\Testing\AssertableInertia;

test('every comparison slug renders the comparison page', function (string $slug) {
    $this->get("/comparativa/{$slug}")
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('comparison')
            ->where('slug', $slug)
        );
})->with(ComparisonController::SLUGS);

test('an unknown comparison slug is not found', function () {
    $this->get('/comparativa/alternativa-a-lo-que-sea')->assertNotFound();
});

test('the sitemap lists every comparison page', function () {
    $response = $this->get('/sitemap.xml')->assertSuccessful();

    foreach (ComparisonController::SLUGS as $slug) {
        expect($response->content())
            ->toContain('<loc>'.config('app.url')."/comparativa/{$slug}</loc>");
    }
});

test('the controller slugs match the frontend data module', function () {
    $module = file_get_contents(resource_path('js/data/comparison-pages.ts'));

    preg_match_all("/slug: '([^']+)'/", $module, $matches);

    expect($matches[1])->toEqualCanonicalizing(ComparisonController::SLUGS);
});
