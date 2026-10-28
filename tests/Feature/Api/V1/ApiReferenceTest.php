<?php

use App\Support\Documentation\ApiReference;
use App\Support\Documentation\OpenApiDocument;
use Illuminate\Support\Facades\File;

it('keeps the committed API reference in step with the code', function () {
    $root = (string) config('documentation.root');
    $pages = ApiReference::current()->pages();

    $committed = collect(File::allFiles($root.'/'.ApiReference::GroupsDirectory))
        ->map(fn (SplFileInfo $file): string => ApiReference::GroupsDirectory.'/'.$file->getRelativePathname())
        ->push('70-api/80-reference-en.md')
        ->sort()
        ->values()
        ->all();

    $stale = 'The API reference is out of date. Run `php artisan api:docs` and commit the result.';

    expect($committed)->toBe(collect(array_keys($pages))->sort()->values()->all(), $stale);

    foreach ($pages as $path => $contents) {
        expect(File::get($root.'/'.$path))->toBe($contents, $stale." ({$path} differs)");
    }
});

it('writes the reference pages, replacing groups that no longer exist', function () {
    $root = storage_path('framework/testing/api-docs-'.uniqid());
    config(['documentation.root' => $root]);
    File::ensureDirectoryExists($root.'/'.ApiReference::GroupsDirectory);
    File::put($root.'/'.ApiReference::GroupsDirectory.'/90-gone-en.md', '# Gone');

    $this->artisan('api:docs')->assertSuccessful();

    expect(File::exists($root.'/'.ApiReference::GroupsDirectory.'/90-gone-en.md'))->toBeFalse()
        ->and(File::get($root.'/'.'70-api/80-reference-en.md'))->toContain('[`GET /api/v1/spaces`](/documentation/api/reference/spaces#list-spaces)')
        ->and(File::get($root.'/'.ApiReference::GroupsDirectory.'/10-spaces-en.md'))
        ->toContain('## List spaces')
        ->toContain('`GET /api/v1/spaces`')
        ->toContain('**Scope:** read.')
        ->toContain('| `data[].id` | string (uuid) |')
        ->toContain('curl https://whisper.money/api/v1/spaces')
        ->toContain('"name": "Personal"')
        ->toContain('| `429` |');

    File::deleteDirectory($root);
});

it('renders the API guides and reference as HTML and markdown', function (string $slug, string $title) {
    $this->get(route('documentation.show', ['slug' => $slug, 'lang' => 'en']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('document.title', $title));

    expect($this->get(route('documentation.markdown', ['slug' => $slug, 'lang' => 'en']))->assertOk()->getContent())
        ->toStartWith('# '.$title);
})->with([
    ['api', 'API'],
    ['api/authentication', 'Authentication'],
    ['api/reference', 'API reference'],
    ['api/reference/spaces', 'Spaces'],
    ['api/changelog', 'Changelog'],
]);

it('serves the English reference in Spanish without passing it off as a translation', function () {
    $this->get(route('documentation.show', ['slug' => 'api/reference/spaces', 'lang' => 'es']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('document.title', 'Spaces')
            ->where('document.locale', 'en')
            ->where('navigation.0.title', 'Primeros pasos'))
        ->assertHeader('Link', implode(', ', [
            '<'.route('documentation.show', ['slug' => 'api/reference/spaces', 'lang' => 'en']).'>; rel="canonical"',
            '<'.route('documentation.show', ['slug' => 'api/reference/spaces', 'lang' => 'en']).'>; rel="alternate"; hreflang="en"',
            '<'.route('documentation.show', ['slug' => 'api/reference/spaces', 'lang' => 'en']).'>; rel="alternate"; hreflang="x-default"',
            '<'.route('documentation.markdown', ['slug' => 'api/reference/spaces', 'lang' => 'es']).'>; rel="alternate"; type="text/markdown"',
        ]));

    $this->get(route('documentation.show', ['slug' => 'api/authentication', 'lang' => 'es']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('document.title', 'Autenticación')->where('document.locale', 'es'));
});

it('lists an untranslated page in the sitemap in its own language only', function () {
    $body = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($body)->toContain('<loc>'.route('documentation.show', ['slug' => 'api/reference', 'lang' => 'en']).'</loc>')
        ->not->toContain('<loc>'.route('documentation.show', ['slug' => 'api/reference', 'lang' => 'es']).'</loc>')
        ->toContain('<loc>'.route('documentation.show', ['slug' => 'api/authentication', 'lang' => 'es']).'</loc>');
});

it('points agents at the API docs and the spec from llms.txt', function () {
    expect($this->get(route('llms'))->assertOk()->getContent())
        ->toContain('## API')
        ->toContain(route('api.v1.openapi'))
        ->toContain(route('documentation.markdown', ['slug' => 'api', 'lang' => 'en']))
        ->toContain(route('documentation.markdown', ['slug' => 'api/authentication', 'lang' => 'en']))
        ->toContain(route('documentation.markdown', ['slug' => 'api/reference', 'lang' => 'en']));
});

it('lists the English-only reference in llms.txt under English alone', function () {
    $spanish = str($this->get(route('llms'))->assertOk()->getContent())->after('## Documentación (español)')->before('## Notes');

    expect((string) $spanish)
        ->toContain(route('documentation.markdown', ['slug' => 'api/authentication', 'lang' => 'es']))
        ->not->toContain(route('documentation.markdown', ['slug' => 'api/reference', 'lang' => 'es']));
});

it('shows only the required fields and those with an example of their own in an example request', function () {
    $body = (new OpenApiDocument([]))->requestExample([
        'type' => 'object',
        'required' => ['name'],
        'properties' => [
            'name' => ['type' => 'string', 'examples' => ['Main checking']],
            'type' => ['type' => 'string'],
            'balance' => ['type' => 'integer', 'example' => 125000],
            'credit_limit' => ['type' => 'integer'],
        ],
    ]);

    expect($body)->toBe(['name' => 'Main checking', 'balance' => 125000]);
});
