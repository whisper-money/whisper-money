<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockDemoAccountActions
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isDemoAccount()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'This action is not available on the demo account.',
                ], 403);
            }

            return back()->withErrors([
                'demo' => 'This action is not available on the demo account.',
            ]);
        }

        return $next($request);
    }
}
