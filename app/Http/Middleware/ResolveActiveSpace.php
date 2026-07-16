<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve (and repair) the authenticated user's active space before controllers
 * run, so every read scoped to `$user->activeSpace()` and every write that fills
 * `space_id` from `current_space_id` sees a valid pointer — even right after a
 * space was deleted or a membership was revoked.
 */
class ResolveActiveSpace
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->user()?->activeSpace();

        return $next($request);
    }
}
