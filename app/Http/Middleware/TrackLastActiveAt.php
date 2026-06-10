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
        $user = $request->user();

        if ($user instanceof User) {
            $now = $user->freshTimestamp();

            if ($user->last_active_at === null
                || $user->last_active_at->lte($now->copy()->subSeconds(self::THROTTLE_SECONDS))) {
                $user->forceFill(['last_active_at' => $now])->saveQuietly();
            }
        }

        return $next($request);
    }
}
