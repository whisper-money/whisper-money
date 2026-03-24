<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

class EnsureOpenBankingFeature
{
    /**
     * Handle an incoming request.
     *
     * Returns 404 if user doesn't have open-banking feature enabled.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! Feature::for($user)->active('open-banking')) {
            abort(404);
        }

        return $next($request);
    }
}
