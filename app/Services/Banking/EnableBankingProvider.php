<?php

namespace App\Services\Banking;

use App\Contracts\BankingProviderInterface;
use App\Exceptions\Banking\ExpiredBankingSessionException;
use App\Exceptions\Banking\TransientBankingProviderException;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
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

    public function startAuthorization(string $aspspName, string $countryCode, string $redirectUrl, string $state): array
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
            'state' => $state,
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

        try {
            $response = $this->client()->get("/accounts/{$accountId}/transactions", $query);

            $response->throw();
        } catch (ConnectionException $e) {
            throw new TransientBankingProviderException(
                'EnableBanking did not respond while fetching account transactions.',
                provider: 'enablebanking',
                previous: $e,
            );
        } catch (RequestException $e) {
            if ($this->isExpiredSession($e)) {
                throw new ExpiredBankingSessionException(
                    'EnableBanking session expired while fetching account transactions.',
                    previous: $e,
                );
            }

            if (! $this->isAspspError($e)) {
                throw $e;
            }

            $body = $this->errorBody($e);
            $providerCode = $body['error'] ?? null;

            throw new TransientBankingProviderException(
                'EnableBanking bank connector failed while fetching account transactions.',
                provider: 'enablebanking',
                statusCode: $e->response->status(),
                providerCode: is_string($providerCode) ? $providerCode : null,
                previous: $e,
            );
        }

        $data = $response->json();

        return [
            'transactions' => $data['transactions'] ?? [],
            'continuation_key' => $data['continuation_key'] ?? null,
        ];
    }

    public function getBalances(string $accountId): array
    {
        try {
            $response = $this->client()->get("/accounts/{$accountId}/balances");

            $response->throw();
        } catch (ConnectionException $e) {
            throw new TransientBankingProviderException(
                'EnableBanking did not respond while fetching account balances.',
                provider: 'enablebanking',
                previous: $e,
            );
        } catch (RequestException $e) {
            if ($this->isExpiredSession($e)) {
                throw new ExpiredBankingSessionException(
                    'EnableBanking session expired while fetching account balances.',
                    previous: $e,
                );
            }

            if (! $this->isAspspError($e)) {
                throw $e;
            }

            $body = $this->errorBody($e);
            $providerCode = $body['error'] ?? null;

            throw new TransientBankingProviderException(
                'EnableBanking bank connector failed while fetching account balances.',
                provider: 'enablebanking',
                statusCode: $e->response->status(),
                providerCode: is_string($providerCode) ? $providerCode : null,
                previous: $e,
            );
        }

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

    private function isAspspError(RequestException $e): bool
    {
        $body = $this->errorBody($e);

        return $e->response->status() === 400
            && ($body['error'] ?? null) === 'ASPSP_ERROR';
    }

    private function isExpiredSession(RequestException $e): bool
    {
        $body = $this->errorBody($e);

        // ponytail: only the documented EXPIRED_SESSION code; widen if other
        // terminal "reconnect required" session codes (e.g. revoked) surface.
        return $e->response->status() === 401
            && ($body['error'] ?? null) === 'EXPIRED_SESSION';
    }

    /**
     * @return array<string, mixed>
     */
    private function errorBody(RequestException $e): array
    {
        $body = $e->response->json();

        return is_array($body) ? $body : [];
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->timeout(20)
            ->connectTimeout(5)
            ->withToken($this->generateJwt())
            ->acceptJson()
            ->throw(function ($response, $exception) {
                $body = $response->json();
                $error = is_array($body) ? ($body['error'] ?? null) : null;
                $isExpected = ($response->status() === 400 && $error === 'ASPSP_ERROR')
                    || ($response->status() === 401 && $error === 'EXPIRED_SESSION');

                Log::log($isExpected ? 'warning' : 'error', 'EnableBanking API error', [
                    'status' => $response->status(),
                    'body' => $body,
                    'exception' => get_class($exception),
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
