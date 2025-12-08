<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WithoutSsr
{
    public function handle(Request $request, Closure $next): Response
    {
        config(['inertia.ssr.enabled' => false]);

        return $next($request);
    }
}
