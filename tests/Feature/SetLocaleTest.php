<?php

use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;

/**
 * Runs a request through the SetLocale middleware and returns the locale the
 * application ended up using.
 */
function resolveLocaleFor(Request $request): string
{
    $request->setLaravelSession(app('session.store'));

    (new SetLocale)->handle($request, fn () => new Response);

    return App::getLocale();
}

it('applies a supported locale from the lang query parameter', function (string $locale) {
    $request = Request::create('/', 'GET', ['lang' => $locale]);

    expect(resolveLocaleFor($request))->toBe($locale);
})->with(['en', 'es', 'fr']);

it('ignores an unsupported lang query parameter', function () {
    $request = Request::create('/', 'GET', ['lang' => 'de']);

    expect(resolveLocaleFor($request))->not->toBe('de');
});

it('detects French from the Accept-Language header', function () {
    $request = Request::create('/', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9,en;q=0.8']);

    expect(resolveLocaleFor($request))->toBe('fr');
});

it('detects Spanish from the Accept-Language header', function () {
    $request = Request::create('/', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'es-ES,es;q=0.9']);

    expect(resolveLocaleFor($request))->toBe('es');
});

it('falls back to English for an unrecognized Accept-Language header', function () {
    $request = Request::create('/', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']);

    expect(resolveLocaleFor($request))->toBe('en');
});
