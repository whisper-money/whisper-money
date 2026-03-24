<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

class ActivateDevelopmentFeatures
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isLocal() && $request->user()) {
            Feature::for($request->user())->activate('open-banking');
            Feature::for($request->user())->activate('account-mapping');
            Feature::for($request->user())->activate('real-estate');
        }

        return $next($request);
    }
}
