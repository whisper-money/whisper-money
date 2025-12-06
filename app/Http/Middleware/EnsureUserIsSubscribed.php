<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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

        if ($request->user()?->hasProPlan()) {
            return $next($request);
        }

        return redirect()->route('subscribe');
    }
}
