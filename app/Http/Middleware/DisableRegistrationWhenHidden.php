<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DisableRegistrationWhenHidden
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            config('landing.hide_auth_buttons', false)
            && $request->is('register')
            && $request->isMethod('POST')
        ) {
            abort(404);
        }

        return $next($request);
    }
}
