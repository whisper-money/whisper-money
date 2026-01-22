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
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
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
        // Priority 1: Check authenticated user's locale preference
        if ($request->user() && $request->user()->locale) {
            return $request->user()->locale;
        }

        // Priority 2: Check session for previously detected locale
        if ($request->session()->has('locale')) {
            return $request->session()->get('locale');
        }

        // Priority 3: Detect from Accept-Language header
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
