<?php

namespace App\Http\Middleware;

use App\Enums\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->determineLocale($request);

        App::setLocale($locale);

        return $next($request);
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
