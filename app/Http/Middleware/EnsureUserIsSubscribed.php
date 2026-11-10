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

        // A paused plan is neither on nor free: every gated page says it is
        // paused and offers to resume it, whatever the user has connected.
        if ($user?->hasPausedSubscription()) {
            return redirect()->route('subscribe');
        }

        if ($user && ! $user->hasPaidFeaturesToGiveUp()) {
            // Without a single transaction there is nothing yet for the plan
            // to do, so the paywall waits until the first ones land.
            if (! $user->hasSeenPaywall() && ! $user->hasNothingToPayForYet()) {
                return redirect()->route('subscribe');
            }

            return $next($request);
        }

        return redirect()->route('subscribe');
    }
}
