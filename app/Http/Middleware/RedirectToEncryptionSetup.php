<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectToEncryptionSetup
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && $request->user()->encryption_salt === null) {
            if (! $request->routeIs('setup-encryption') && ! $request->is('api/encryption/setup')) {
                return redirect()->route('setup-encryption');
            }
        }

        return $next($request);
    }
}
