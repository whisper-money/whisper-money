<?php

namespace App\Services\Banking;

use App\Exceptions\Banking\TransientBankingProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WiseClient
{
    private const BASE_URL = 'https://api.wise.com';

    public function __construct(private string $apiToken) {}

    /**
     * @return array<int, array{id: int, type: string, details: array}>
     */
    public function getProfiles(): array
    {
        return $this->get('/v1/profiles');
    }

    /**
     * Get the multi-currency borderless account for a profile.
     *
     * @return array{id?: int, profileId?: int, balances?: array}
     */
    public function getBorderlessAccount(int $profileId): array
    {
        $accounts = $this->get('/v2/borderless-accounts', ['profileId' => $profileId]);

        return $accounts[0] ?? [];
    }

    /**
     * Fetch paginated monetary activities for a profile.
     * Use `since`/`until` (ISO 8601) for date range and `cursor` for pagination.
     *
     * @return array{activities?: array, cursor?: string|null}
     */
    public function getActivities(int $profileId, string $since, string $until, ?string $cursor = null): array
    {
        $params = [
            'size' => 100,
            'since' => $since,
            'until' => $until,
        ];

        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        return $this->get("/v1/profiles/{$profileId}/activities", $params);
    }

    /**
     * An unattended sync cannot do anything about Wise being down or slow, so a
     * timeout or a 5xx is reclassified as transient: the job logs it as a
     * warning, keeps it out of Sentry and retries later. Statuses the caller
     * acts on - 401/403 auth failures, 429 rate limits - stay as they are.
     *
     * @param  array<string, mixed>  $params
     * @return array<mixed>
     */
    private function get(string $url, array $params = []): array
    {
        try {
            $response = $this->client()->get($url, $params);

            $response->throw();
        } catch (ConnectionException $e) {
            throw new TransientBankingProviderException(
                'Wise did not respond in time.',
                provider: 'wise',
                previous: $e,
            );
        } catch (RequestException $e) {
            if (! $e->response->serverError()) {
                throw $e;
            }

            throw new TransientBankingProviderException(
                'Wise could not serve the request right now.',
                provider: 'wise',
                statusCode: $e->response->status(),
                previous: $e,
            );
        }

        return $response->json();
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($this->apiToken)
            ->acceptJson()
            ->throw(function ($response, RequestException $exception) {
                Log::log($response->serverError() ? 'warning' : 'error', 'Wise API error', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
            });
    }
}
