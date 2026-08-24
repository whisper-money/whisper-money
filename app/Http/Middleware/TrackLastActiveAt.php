<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackLastActiveAt
{
    /**
     * Only write once per this many seconds to avoid a database write on every request.
     */
    private const THROTTLE_SECONDS = 300;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        if ($user instanceof User) {
            $lastActiveAt = $user->last_active_at;

            if ($lastActiveAt === null
                || $lastActiveAt->lte(now()->subSeconds(self::THROTTLE_SECONDS))) {
                $user->last_active_at = now();
                $user->saveQuietly();
            }
        }

        return $response;
    }
}
