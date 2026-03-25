<?php

namespace App\Http\Middleware;

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
        if ($request->has('lang') && in_array($request->get('lang'), ['en', 'es'])) {
            $locale = $request->get('lang');
            // Store in session so subsequent requests remember this choice
            $request->session()->put('locale', $locale);

            return $locale;
        }

        // Priority 2: Check authenticated user's locale preference
        if ($request->user() && $request->user()->locale) {
            return $request->user()->locale;
        }

        // Priority 2b: Authenticated user without locale — detect and persist
        if ($request->user()) {
            $detected = $this->detectLocaleFromHeader($request);

            if (in_array($request->session()->get('locale'), ['en', 'es'])) {
                $detected = $request->session()->get('locale');
            }

            $request->user()->update(['locale' => $detected]);

            return $detected;
        }

        // Priority 3: Check session for previously detected locale
        if ($request->session()->has('locale')) {
            return $request->session()->get('locale');
        }

        // Priority 4: Detect from Accept-Language header
        $detected = $this->detectLocaleFromHeader($request);

        // Store in session for subsequent requests
        $request->session()->put('locale', $detected);

        return $detected;
    }

    /**
     * Detect locale from Accept-Language header.
     */
    protected function detectLocaleFromHeader(Request $request): string
    {
        $acceptLanguage = $request->header('Accept-Language', '');

        // Check if Spanish is preferred
        if (preg_match('/^es(-|,|;)/i', $acceptLanguage) || $acceptLanguage === 'es') {
            return 'es';
        }

        return 'en';
    }
}
