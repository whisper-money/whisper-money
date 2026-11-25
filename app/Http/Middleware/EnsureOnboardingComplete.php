<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnboardingComplete
{
    /**
     * Handle an incoming request.
     *
     * Redirects non-onboarded users to the onboarding flow.
     * Redirects onboarded users away from the onboarding page.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $isOnboardingRoute = $request->routeIs('onboarding') || $request->routeIs('onboarding.*');
        $isOpenBankingRoute = $request->routeIs('open-banking.*');

        if ($user->isOnboarded()) {
            // The emailed import link is asked for from the dashboard too, by
            // a user whose only movements are the onboarding estimates.
            if ($isOnboardingRoute && ! $request->routeIs('onboarding.import-link.store')) {
                return redirect()->route('dashboard');
            }

            return $next($request);
        }

        // Allow non-onboarded users through open-banking routes so they can
        // connect their bank during the onboarding flow.
        if ($isOpenBankingRoute) {
            return $next($request);
        }

        if (! $isOnboardingRoute) {
            return redirect()->route('onboarding');
        }

        return $next($request);
    }
}
