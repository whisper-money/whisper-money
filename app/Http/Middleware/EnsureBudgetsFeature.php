<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

class EnsureBudgetsFeature
{
    /**
     * Handle an incoming request.
     *
     * Returns 404 if user doesn't have budgets feature enabled.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! Feature::for($user)->active('budgets')) {
            abort(404);
        }

        return $next($request);
    }
}
