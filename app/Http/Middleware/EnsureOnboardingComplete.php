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
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $isOnboardingRoute = $request->routeIs('onboarding') || $request->routeIs('onboarding.*');

        if ($user->isOnboarded()) {
            if ($isOnboardingRoute) {
                return redirect()->route('dashboard');
            }

            return $next($request);
        }

        if (! $isOnboardingRoute) {
            return redirect()->route('onboarding');
        }

        return $next($request);
    }
}
