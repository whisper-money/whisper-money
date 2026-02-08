<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockDemoAccountActions
{
    private array $fortifyBlockedRoutes = [
        'user/two-factor-authentication',
        'user/confirmed-two-factor-authentication',
        'user/two-factor-recovery-codes',
    ];

    private array $blockedMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        if (! $request->user()?->isDemoAccount() || app()->environment('local')) {
            return $next($request);
        }

        $path = $request->path();
        $method = $request->method();

        // When running globally with 'auto' mode, only block specific Fortify routes
        // When explicitly applied (mode is null), block all mutating actions
        $shouldBlock = $mode === 'auto'
            ? (in_array($path, $this->fortifyBlockedRoutes) && in_array($method, $this->blockedMethods))
            : in_array($method, $this->blockedMethods);

        if ($shouldBlock) {
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
