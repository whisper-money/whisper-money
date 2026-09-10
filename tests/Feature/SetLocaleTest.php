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

it('pins the region the browser asks for onto a user who has none', function () {
    $user = User::factory()->create(['locale' => 'es', 'format_locale' => null]);

    expect(formatLocaleAfter($user, 'es-MX,es;q=0.9,en;q=0.8'))->toBe('es-MX');
});

it('reads the region rather than the language, even when the two disagree', function () {
    // A reader who set the app to English on a British browser gets British
    // amounts, not American ones: language and region are separate choices.
    $user = User::factory()->create(['locale' => 'en', 'format_locale' => null]);

    expect(formatLocaleAfter($user, 'en-GB,en;q=0.9'))->toBe('en-GB');
});

it('honours the quality the browser puts on each language', function () {
    $user = User::factory()->create(['locale' => 'es', 'format_locale' => null]);

    expect(formatLocaleAfter($user, 'en;q=0.5,es-AR;q=0.9'))->toBe('es-AR');
});

it('leaves a header with no region where the reader already was', function (string $locale, string $expected) {
    $user = User::factory()->create(['locale' => $locale, 'format_locale' => null]);

    expect(formatLocaleAfter($user, $locale))->toBe($expected);
})->with([['es', 'es-ES'], ['en', 'en-US'], ['fr', 'fr-FR']]);

it('takes the Latin American tag a browser sends instead of a country', function () {
    // Chrome and Android send this for "Spanish (Latin America)", and reading
    // it as plain Spanish is what put Spain's separators on Mexican screens.
    $user = User::factory()->create(['locale' => 'es', 'format_locale' => null]);

    expect(formatLocaleAfter($user, 'es-419,es;q=0.9'))->toBe('es-419');
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

it('falls back to the language when the header names a region the app does not offer', function () {
    $user = User::factory()->create(['locale' => 'fr', 'format_locale' => null]);

    expect(formatLocaleAfter($user, 'nb-NO,nb;q=0.9'))->toBe('fr-FR');
});

it('falls back to the language when there is no header at all', function () {
    $user = User::factory()->create(['locale' => 'es', 'format_locale' => null]);

    expect(formatLocaleAfter($user, null))->toBe('es-ES');
});

it('never overwrites a region the reader already has', function () {
    // The header is a guess and the stored value may be a deliberate choice, so
    // signing in from a borrowed laptop must not rewrite it.
    $user = User::factory()->create(['locale' => 'es', 'format_locale' => 'es-MX']);

    expect(formatLocaleAfter($user, 'en-US,en;q=0.9'))->toBe('es-MX');
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
