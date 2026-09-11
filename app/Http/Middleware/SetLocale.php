<?php

namespace App\Http\Middleware;

use App\Enums\Locale;
use App\Http\Controllers\Settings\TimezoneController;
use App\Services\FormatLocaleOptions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function __construct(private FormatLocaleOptions $formatLocales) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->determineLocale($request);

        // Two letters, always: `App::setLocale('es-MX')` would send the
        // translator looking for a `lang/es-MX/` that does not exist. The
        // region lives on the user instead, and reaches the client through
        // the `locale` prop {@see HandleInertiaRequests::share()} shares.
        App::setLocale($locale);

        $this->rememberFormatLocale($request, $locale);

        return $next($request);
    }

    /**
     * Pin the region the browser asks for onto the user, once.
     *
     * Written only when the column is still empty, the same way
     * {@see TimezoneController} settles a timezone: the header is a guess, and
     * a reader who has since picked a region in their settings must not have it
     * overwritten by whichever browser they happen to be signed in on today.
     *
     * Existing accounts are filled in the same way on their next request, which
     * is what carries the fix to them without a data migration.
     */
    protected function rememberFormatLocale(Request $request, string $locale): void
    {
        $user = $request->user();

        if ($user === null || $user->format_locale !== null) {
            return;
        }

        // The reader's own language decides the fallback, not the one this
        // request resolved to: a `?lang=` on the link they arrived through is a
        // display override, and it has no business deciding where their decimal
        // separator lands for good.
        $user->update([
            'format_locale' => $this->formatLocales->detectFromHeader(
                $request->header('Accept-Language'),
                $user->locale ?? $locale,
            ),
        ]);
    }

    /**
     * Determine the locale for the current request.
     */
    protected function determineLocale(Request $request): string
    {
        // Priority 1: Check for lang query parameter (user override on welcome page)
        $lang = $request->get('lang');

        if (is_string($lang) && Locale::tryFrom($lang) !== null) {
            // Store in session so subsequent requests remember this choice
            $request->session()->put('locale', $lang);

            return $lang;
        }

        // Priority 2: Check authenticated user's locale preference
        if ($request->user() && $request->user()->locale) {
            return $request->user()->locale;
        }

        // Priority 2b: Authenticated user without locale — detect and persist
        if ($request->user()) {
            $sessionLocale = $request->session()->get('locale');

            $detected = is_string($sessionLocale) && Locale::tryFrom($sessionLocale) !== null
                ? $sessionLocale
                : Locale::detectFromHeader($request->header('Accept-Language'))->value;

            $request->user()->update(['locale' => $detected]);

            return $detected;
        }

        // Priority 3: Check session for previously detected locale
        if ($request->session()->has('locale')) {
            return $request->session()->get('locale');
        }

        // Priority 4: Detect from Accept-Language header
        $detected = Locale::detectFromHeader($request->header('Accept-Language'))->value;

        // Store in session for subsequent requests
        $request->session()->put('locale', $detected);

        return $detected;
    }
}
