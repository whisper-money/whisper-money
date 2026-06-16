<?php

namespace App\Services\Banking;

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
        $response = $this->client()->get('/v1/profiles');

        $response->throw();

        return $response->json();
    }

    /**
     * Get the multi-currency borderless account for a profile.
     *
     * @return array{id?: int, profileId?: int, balances?: array}
     */
    public function getBorderlessAccount(int $profileId): array
    {
        $response = $this->client()->get('/v2/borderless-accounts', [
            'profileId' => $profileId,
        ]);

        $response->throw();

        $accounts = $response->json();

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

        $response = $this->client()->get("/v1/profiles/{$profileId}/activities", $params);

        $response->throw();

        return $response->json();
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($this->apiToken)
            ->acceptJson()
            ->throw(function ($response, RequestException $exception) {
                Log::error('Wise API error', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
            });
    }
}
