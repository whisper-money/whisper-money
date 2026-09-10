<?php

namespace App\Services\Banking\Concerns;

use App\Enums\BankingProvider;
use App\Exceptions\Banking\TransientBankingProviderException;
use App\Services\Banking\WiseClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Shared transport handling for the API-key banking clients, mirroring the one
 * {@see WiseClient} already carries.
 *
 * An unattended sync can do nothing about a provider being down or slow, so a
 * timeout or a 5xx is reclassified as transient: the job logs it as a warning,
 * keeps it out of Sentry, and leaves the connection's budget of scheduled
 * retries alone rather than spending it on an outage. Statuses the caller acts
 * on - 401/403 auth failures, 429 rate limits - stay as they are.
 *
 * Consumers name themselves through {@see provider()}.
 */
trait TranslatesTransportFailures
{
    /**
     * Explicit rather than the framework's 30s default: with no timeout at all a
     * hung provider holds a queue worker for as long as it likes.
     */
    private const int HTTP_TIMEOUT_SECONDS = 15;

    private const int HTTP_CONNECT_TIMEOUT_SECONDS = 5;

    abstract protected function provider(): BankingProvider;

    /**
     * A request builder that cannot outlive the timeouts above.
     */
    protected function transportClient(string $baseUrl): PendingRequest
    {
        return Http::baseUrl($baseUrl)
            ->timeout(self::HTTP_TIMEOUT_SECONDS)
            ->connectTimeout(self::HTTP_CONNECT_TIMEOUT_SECONDS);
    }

    /**
     * Run a request, reclassifying its transport failures as transient.
     *
     * Wrap the whole `retry()` call, never the callback inside it: a translated
     * exception no longer matches the `when:` closure that retries a 429.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $request
     * @return TResult
     */
    protected function translateTransportFailures(callable $request): mixed
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            throw new TransientBankingProviderException(
                "{$this->provider()->name} did not respond in time.",
                provider: $this->provider()->value,
                previous: $e,
            );
        } catch (RequestException $e) {
            if (! $e->response->serverError()) {
                throw $e;
            }

            throw new TransientBankingProviderException(
                "{$this->provider()->name} could not serve the request right now.",
                provider: $this->provider()->value,
                statusCode: $e->response->status(),
                previous: $e,
            );
        }
    }
}
