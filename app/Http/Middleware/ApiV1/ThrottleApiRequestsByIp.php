<?php

namespace App\Http\Middleware\ApiV1;

use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `/api/v1` per-IP throttle (the `api-v1-ip` limiter), run before
 * authentication so a flood of bogus bearer tokens is capped too.
 *
 * Not Laravel's `throttle` middleware on purpose: the router would sort that
 * after AuthenticateApiRequest (an AuthenticatesRequests), and its
 * X-RateLimit-* headers would overwrite the per-user budget clients read.
 * This one only answers a 429, with Retry-After, once the IP is over.
 */
class ThrottleApiRequestsByIp
{
    public const string Limiter = 'api-v1-ip';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Limit $limit */
        $limit = RateLimiter::limiter(self::Limiter)($request);
        $key = self::Limiter.':'.$limit->key;

        if (RateLimiter::tooManyAttempts($key, $limit->maxAttempts)) {
            throw new ThrottleRequestsException('Too Many Attempts.', headers: [
                'Retry-After' => RateLimiter::availableIn($key),
            ]);
        }

        RateLimiter::hit($key, $limit->decaySeconds);

        return $next($request);
    }
}
