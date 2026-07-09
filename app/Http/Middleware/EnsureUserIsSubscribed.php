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
        $space = $user?->activeSpace();

        // The active space's owner plan governs access: a member of a Business
        // space gets in even without a plan of their own.
        if ($space?->owner?->hasProPlan()) {
            return $next($request);
        }

        if ($space && ! $space->bankingConnections()->exists() && ! $user->hasActiveAiConsent()) {
            if (! $user->hasSeenPaywall()) {
                return redirect()->route('subscribe');
            }

            return $next($request);
        }

        return redirect()->route('subscribe');
    }
}
