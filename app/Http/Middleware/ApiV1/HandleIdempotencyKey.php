<?php

namespace App\Http\Middleware\ApiV1;

use App\Http\ApiV1\ApiV1Exception;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional `Idempotency-Key` header on `/api/v1` POSTs, so a client can retry
 * a create after a timeout without creating it twice. The first response for
 * a (user, key) pair is cached for 24 hours and replayed verbatim, flagged
 * with `Idempotent-Replayed: true`. Reusing the key for a different request
 * is a 422, and a retry racing the original still in flight is a 409.
 *
 * Only successful responses are kept: a retry after a validation error or a
 * 5xx runs again, so the client can fix the body without minting a new key.
 */
class HandleIdempotencyKey
{
    private const string Header = 'Idempotency-Key';

    private const int TtlSeconds = 86400;

    private const int MaxKeyLength = 255;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::Header);

        if (! $request->isMethod('POST') || $key === null) {
            return $next($request);
        }

        if ($key === '' || strlen($key) > self::MaxKeyLength) {
            throw ApiV1Exception::badRequest('invalid_idempotency_key', 'The Idempotency-Key header must be between 1 and 255 characters.');
        }

        $cacheKey = 'api-v1:idempotency:'.$request->user()?->getAuthIdentifier().':'.hash('sha256', $key);
        $fingerprint = hash('sha256', $request->method().' '.$request->getRequestUri()."\n".$request->getContent());

        $lock = Cache::lock($cacheKey.':lock', 30);

        if (! $lock->get()) {
            throw new ApiV1Exception(409, 'idempotency_conflict', 'A request with this Idempotency-Key is still being processed.');
        }

        try {
            return $this->replay(Cache::get($cacheKey), $fingerprint)
                ?? $this->store($cacheKey, $fingerprint, $next($request));
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{fingerprint: string, status: int, content_type: ?string, location: ?string, body: string}|null  $stored
     */
    private function replay(?array $stored, string $fingerprint): ?Response
    {
        if ($stored === null) {
            return null;
        }

        if (! hash_equals($stored['fingerprint'], $fingerprint)) {
            throw ValidationException::withMessages([
                self::Header => 'This Idempotency-Key was already used with a different request.',
            ]);
        }

        return new HttpResponse($stored['body'], $stored['status'], array_filter([
            'Content-Type' => $stored['content_type'],
            'Location' => $stored['location'],
            'Idempotent-Replayed' => 'true',
        ]));
    }

    private function store(string $cacheKey, string $fingerprint, Response $response): Response
    {
        if ($response->isSuccessful()) {
            Cache::put($cacheKey, [
                'fingerprint' => $fingerprint,
                'status' => $response->getStatusCode(),
                'content_type' => $response->headers->get('Content-Type'),
                'location' => $response->headers->get('Location'),
                'body' => (string) $response->getContent(),
            ], self::TtlSeconds);
        }

        return $response;
    }
}
