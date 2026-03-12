<?php

namespace App\Services\Banking;

use App\Contracts\BankingProviderInterface;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EnableBankingProvider implements BankingProviderInterface
{
    private const BASE_URL = 'https://api.enablebanking.com';

    public function __construct(
        private string $appId,
        private string $privateKeyPath,
    ) {}

    public function getInstitutions(string $countryCode): array
    {
        $response = $this->client()->get('/aspsps', [
            'country' => $countryCode,
            'psu_type' => 'personal',
        ]);

        $response->throw();

        return collect($response->json('aspsps', []))
            ->map(fn (array $aspsp) => [
                'name' => $aspsp['name'],
                'country' => $aspsp['country'],
                'logo' => $aspsp['logo'] ?? null,
                'maximum_consent_validity' => $aspsp['maximum_consent_validity'] ?? null,
            ])
            ->all();
    }

    public function startAuthorization(string $aspspName, string $countryCode, string $redirectUrl): array
    {
        $response = $this->client()->post('/auth', [
            'access' => [
                'valid_until' => now()->addDays(90)->toIso8601String(),
                'balances' => true,
                'transactions' => true,
            ],
            'aspsp' => [
                'name' => $aspspName,
                'country' => $countryCode,
            ],
            'state' => csrf_token(),
            'redirect_url' => $redirectUrl,
            'psu_type' => 'personal',
        ]);

        $response->throw();

        $data = $response->json();

        return [
            'url' => $data['url'],
            'authorization_id' => $data['authorization_id'],
        ];
    }

    public function createSession(string $code): array
    {
        $response = $this->client()->post('/sessions', [
            'code' => $code,
        ]);

        $response->throw();

        return $response->json();
    }

    public function getTransactions(string $accountId, string $dateFrom, string $dateTo, ?string $continuationKey = null, ?string $strategy = null): array
    {
        $query = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];

        if ($continuationKey) {
            $query['continuation_key'] = $continuationKey;
        }

        if ($strategy) {
            $query['strategy'] = $strategy;
        }

        $response = $this->client()->get("/accounts/{$accountId}/transactions", $query);

        $response->throw();

        $data = $response->json();

        return [
            'transactions' => $data['transactions'] ?? [],
            'continuation_key' => $data['continuation_key'] ?? null,
        ];
    }

    public function getBalances(string $accountId): array
    {
        $response = $this->client()->get("/accounts/{$accountId}/balances");

        $response->throw();

        return $response->json();
    }

    public function getSession(string $sessionId): array
    {
        $response = $this->client()->get("/sessions/{$sessionId}");

        $response->throw();

        return $response->json();
    }

    public function getAccount(string $accountId): array
    {
        $response = $this->client()->get("/accounts/{$accountId}/details");

        $response->throw();

        return $response->json();
    }

    public function revokeSession(string $sessionId): void
    {
        $response = $this->client()->delete("/sessions/{$sessionId}");

        $response->throw();
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($this->generateJwt())
            ->acceptJson()
            ->throw(function ($response, $exception) {
                Log::error('EnableBanking API error', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
            });
    }

    private function generateJwt(): string
    {
        $now = time();

        $payload = [
            'iss' => 'enablebanking.com',
            'aud' => 'api.enablebanking.com',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $privateKey = file_get_contents($this->privateKeyPath);

        return JWT::encode($payload, $privateKey, 'RS256', $this->appId);
    }
}
