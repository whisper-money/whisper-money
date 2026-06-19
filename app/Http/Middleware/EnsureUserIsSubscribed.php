<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsSubscribed
{
    /**
     * @param  Closure(Request): (Response)  $next
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

        if ($user && ! $user->bankingConnections()->exists() && ! $user->hasActiveAiConsent()) {
            if (! $user->hasSeenPaywall()) {
                return redirect()->route('subscribe');
            }

            return $next($request);
        }

        return redirect()->route('subscribe');
    }
}
