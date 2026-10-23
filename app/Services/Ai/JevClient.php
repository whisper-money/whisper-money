<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * The HTTP plumbing shared by every TypeSafe AI Jev caller: endpoint, bearer
 * token, timeouts, what counts as a transient failure and the in-place retry.
 *
 * A rate-limited (429), overloaded (529 or any other 5xx) or unreachable
 * request is transient: it is retried a couple of times with a short backoff
 * before the caller gives up on it.
 */
class JevClient
{
    public const string ENDPOINT = 'https://api.typesafe.ai/v1/systemone';

    public const int MAX_ATTEMPTS = 3;

    public function model(): string
    {
        return (string) config('services.typesafe.model');
    }

    /**
     * A request to Jev, authenticated and with its timeouts set, on the given
     * HTTP factory or concurrent pool.
     */
    public function request(Factory|Pool $http, int $timeout = 30): PendingRequest
    {
        return $http->withToken((string) config('services.typesafe.key'))
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout($timeout);
    }

    /**
     * Send one request, retrying it in place while it fails transiently.
     *
     * @param  array<string, mixed>  $body
     * @param  int  $timeout  seconds per attempt; keep it short when a user is waiting on the answer
     * @return Response|null the response, failed or not, or null when every attempt failed transiently
     */
    public function send(array $body, int $timeout = 30): ?Response
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->request(Http::getFacadeRoot(), $timeout)->post(self::ENDPOINT, $body);
            } catch (ConnectionException $exception) {
                $response = $exception;
            }

            if (! $this->isTransient($response)) {
                return $response;
            }

            if ($attempt === self::MAX_ATTEMPTS) {
                return null;
            }

            $this->backOff($attempt);
        }
    }

    public function backOff(int $attempt): void
    {
        Sleep::for($attempt)->seconds();
    }

    public function isTransient(mixed $response): bool
    {
        return $response instanceof ConnectionException
            || ($response instanceof Response && ($response->tooManyRequests() || $response->serverError()));
    }
}
