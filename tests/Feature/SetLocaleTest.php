<?php

use App\Http\Middleware\SetLocale;
use App\Models\User;
use App\Services\FormatLocaleOptions;
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

    app(SetLocale::class)->handle($request, fn () => new Response);

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

it('detects the locale from the Accept-Language header', function (string $acceptLanguage, string $expected) {
    $request = Request::create('/', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => $acceptLanguage]);

    expect(resolveLocaleFor($request))->toBe($expected);
})->with([
    'French' => ['fr-FR,fr;q=0.9,en;q=0.8', 'fr'],
    'Spanish' => ['es-ES,es;q=0.9', 'es'],
    'unrecognized falls back to English' => ['de-DE,de;q=0.9', 'en'],
]);

/**
 * Runs a request through the middleware as `$user` and hands back their row,
 * refreshed: the middleware writes to it, and the caller wants to see what.
 */
function formatLocaleAfter(User $user, ?string $acceptLanguage): ?string
{
    // Explicitly emptied rather than left out: `Request::create()` supplies an
    // `en-us` header of its own when none is given, which is not what "no
    // header" means here.
    $request = Request::create('/', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => (string) $acceptLanguage]);
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn (): User => $user);

    app(SetLocale::class)->handle($request, fn () => new Response);

    return $user->refresh()->format_locale;
}

it('pins the region the browser asks for onto a user who has none', function (string $locale, ?string $acceptLanguage, string $expected) {
    $user = User::factory()->create(['locale' => $locale, 'format_locale' => null]);

    expect(formatLocaleAfter($user, $acceptLanguage))->toBe($expected);
})->with([
    'country of the language' => ['es', 'es-MX,es;q=0.9,en;q=0.8', 'es-MX'],
    // A reader who set the app to English on a British browser gets British
    // amounts, not American ones: language and region are separate choices.
    'region even when the language disagrees' => ['en', 'en-GB,en;q=0.9', 'en-GB'],
    'highest quality language' => ['es', 'en;q=0.5,es-AR;q=0.9', 'es-AR'],
    // Chrome and Android send this for "Spanish (Latin America)", and reading
    // it as plain Spanish is what put Spain's separators on Mexican screens.
    'Latin American tag instead of a country' => ['es', 'es-419,es;q=0.9', 'es-419'],
    'language fallback for a region the app does not offer' => ['fr', 'nb-NO,nb;q=0.9', 'fr-FR'],
    'language fallback without a header' => ['es', null, 'es-ES'],
    'header without a region keeps Spanish' => ['es', 'es', 'es-ES'],
    'header without a region keeps English' => ['en', 'en', 'en-US'],
    'header without a region keeps French' => ['fr', 'fr', 'fr-FR'],
]);

it('never overwrites a region the reader already has', function () {
    // The header is a guess and the stored value may be a deliberate choice, so
    // signing in from a borrowed laptop must not rewrite it.
    $user = User::factory()->create(['locale' => 'es', 'format_locale' => 'es-MX']);

    expect(formatLocaleAfter($user, 'en-US,en;q=0.9'))->toBe('es-MX');
});

it('does not let a ?lang= override decide the region fallback', function () {
    // The query parameter switches the language of the page being looked at;
    // the region is settled once and for good, so it follows the reader's own.
    $user = User::factory()->create(['locale' => 'es', 'format_locale' => null]);

    $request = Request::create('/', 'GET', ['lang' => 'en'], server: ['HTTP_ACCEPT_LANGUAGE' => 'nb-NO']);
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn (): User => $user);

    app(SetLocale::class)->handle($request, fn () => new Response);

    expect($user->refresh()->format_locale)->toBe('es-ES');
});

it('reads a region for a signed-out visitor without storing anything', function () {
    $request = Request::create('/', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'es-CO,es;q=0.9']);
    $request->setLaravelSession(app('session.store'));

    app(SetLocale::class)->handle($request, fn () => new Response);

    expect(app(FormatLocaleOptions::class)->detectFromHeader('es-CO,es;q=0.9', 'es'))->toBe('es-CO');
});

it('can write an amount in every region it offers', function () {
    // A tag ICU does not know leaves its failure on the formatter rather than in
    // the return value, and a duplicate would quietly double a row in the picker.
    $codes = app(FormatLocaleOptions::class)->codes();

    expect($codes)->not->toBeEmpty()->and(array_unique($codes))->toBe($codes);

    foreach ($codes as $code) {
        $formatter = new NumberFormatter($code, NumberFormatter::CURRENCY);
        $formatter->formatCurrency(1234.56, 'EUR');

        expect(intl_is_failure($formatter->getErrorCode()))->toBeFalse("ICU does not know {$code}");
    }
});
