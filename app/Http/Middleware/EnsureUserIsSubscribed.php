<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsSubscribed
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('subscriptions.enabled')) {
            return $next($request);
        }

        $user = $request->user();

        if ($user?->hasProPlan()) {
            return $next($request);
        }

        // If Open Banking is enabled and the user has no bank connections,
        // they may use the app for free — but they must first see the paywall
        // so they can make an informed choice.
        if ($user && Feature::for($user)->active('open-banking') && ! $user->bankingConnections()->exists()) {
            if (! $user->hasSeenPaywall()) {
                return redirect()->route('subscribe');
            }

            return $next($request);
        }

        return redirect()->route('subscribe');
    }
}
