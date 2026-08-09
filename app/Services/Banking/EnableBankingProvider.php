<?php

namespace App\Services\Banking;

use App\Contracts\BankingProviderInterface;
use App\Exceptions\Banking\ExpiredBankingSessionException;
use App\Exceptions\Banking\InaccessibleBankAccountException;
use App\Exceptions\Banking\TransientBankingProviderException;
use App\Exceptions\Banking\WrongTransactionsPeriodException;
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
            if ($this->requiresReconnect($e)) {
                throw new ExpiredBankingSessionException(
                    'EnableBanking needs the user to reconnect before it will serve account transactions.',
                    previous: $e,
                );
            }

            if ($this->isInaccessibleAccount($e)) {
                throw new InaccessibleBankAccountException(
                    'EnableBanking account is no longer accessible while fetching transactions.',
                    previous: $e,
                );
            }

            if ($this->isWrongPeriod($e)) {
                throw new WrongTransactionsPeriodException(
                    'EnableBanking rejected the requested transactions period as too wide.',
                    previous: $e,
                );
            }

            if ($this->isTransientServerError($e)) {
                throw new TransientBankingProviderException(
                    'EnableBanking returned a server error while fetching account transactions.',
                    provider: 'enablebanking',
                    statusCode: $e->response->status(),
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
            if ($this->requiresReconnect($e)) {
                throw new ExpiredBankingSessionException(
                    'EnableBanking needs the user to reconnect before it will serve account balances.',
                    previous: $e,
                );
            }

            if ($this->isInaccessibleAccount($e)) {
                throw new InaccessibleBankAccountException(
                    'EnableBanking account is no longer accessible while fetching balances.',
                    previous: $e,
                );
            }

            if ($this->isTransientServerError($e)) {
                throw new TransientBankingProviderException(
                    'EnableBanking returned a server error while fetching account balances.',
                    provider: 'enablebanking',
                    statusCode: $e->response->status(),
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

    private function isTransientServerError(RequestException $e): bool
    {
        // Any upstream 5xx (EnableBanking itself or the ASPSP behind it) is a
        // transient server-side failure — same class as a ConnectionException,
        // so retry/self-heal rather than report it as an app error.
        return $e->response->status() >= 500;
    }

    /**
     * Whether the bank will not serve this connection again until the user
     * authorizes it afresh. Three codes, one remedy — the user reconnects:
     *
     * - 401 EXPIRED_SESSION: the consent window lapsed.
     * - 401 CLOSED_SESSION: the session was closed (revoked at the bank, or
     *   superseded by a newer authorization).
     * - 403 PsuActionRequiredException: the bank wants the user present —
     *   typically a fresh SCA before it will keep serving unattended access.
     */
    private function requiresReconnect(RequestException $e): bool
    {
        $body = $this->errorBody($e);
        $detail = $body['detail'] ?? null;

        return match ($e->response->status()) {
            401 => in_array($body['error'] ?? null, ['EXPIRED_SESSION', 'CLOSED_SESSION'], true),
            403 => is_array($detail) && ($detail['error_name'] ?? null) === 'PsuActionRequiredException',
            default => false,
        };
    }

    private function isInaccessibleAccount(RequestException $e): bool
    {
        $detail = $this->errorBody($e)['detail'] ?? null;
        $errorName = is_array($detail) ? ($detail['error_name'] ?? null) : null;

        // ponytail: the documented per-account 400; widen if other terminal
        // account-level codes surface for a single account.
        return $e->response->status() === 400
            && $errorName === 'AccountNotAccessibleException';
    }

    private function isWrongPeriod(RequestException $e): bool
    {
        $message = $this->errorBody($e)['message'] ?? null;

        // The bank refused the requested date range as too wide ("Wrong
        // transactions period requested"). Keyed on 422 + the stable "period"
        // token so genuine validation 422s (e.g. malformed dates) still surface.
        // ponytail: message match; if EnableBanking adds a stable error code for
        // this, key on that instead.
        return $e->response->status() === 422
            && is_string($message)
            && str_contains(strtolower($message), 'period');
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
            ->throw(function ($response, RequestException $exception) {
                // Expected outcomes of an unattended sync — a flaky bank connector,
                // a consent the user has to renew, a period the bank won't serve —
                // are the caller's to handle, so they log as warnings rather than
                // as application errors.
                $isExpected = $this->isAspspError($exception)
                    || $this->requiresReconnect($exception)
                    || $response->status() === 422;

                Log::log($isExpected ? 'warning' : 'error', 'EnableBanking API error', [
                    'status' => $response->status(),
                    'body' => $response->json(),
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
