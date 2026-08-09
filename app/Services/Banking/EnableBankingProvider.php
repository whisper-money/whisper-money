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

            if ($this->isTransientServerError($e) || $this->isPsuActionRequired($e)) {
                throw new TransientBankingProviderException(
                    'EnableBanking could not serve account transactions right now.',
                    provider: 'enablebanking',
                    statusCode: $e->response->status(),
                    providerCode: $this->detailErrorName($e),
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

            if ($this->isTransientServerError($e) || $this->isPsuActionRequired($e)) {
                throw new TransientBankingProviderException(
                    'EnableBanking could not serve account balances right now.',
                    provider: 'enablebanking',
                    statusCode: $e->response->status(),
                    providerCode: $this->detailErrorName($e),
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
     * Whether the session itself is gone, so the bank will serve nothing more
     * on this connection until the user authorizes it afresh: the consent
     * window lapsed (EXPIRED_SESSION), or the session was closed — revoked at
     * the bank, or superseded by a newer authorization (CLOSED_SESSION).
     */
    private function requiresReconnect(RequestException $e): bool
    {
        return $e->response->status() === 401
            && in_array($this->errorBody($e)['error'] ?? null, ['EXPIRED_SESSION', 'CLOSED_SESSION'], true);
    }

    /**
     * The bank asked for the user to be present — nominally a fresh SCA before
     * it keeps serving unattended access.
     *
     * Deliberately treated as transient rather than as a reconnect: in the one
     * burst we have seen, nine connections at nine different banks, all with
     * months of consent left, failed inside the same nine-minute sync window
     * hours after syncing cleanly, and none recurred. That is the provider
     * faulting, not nine users revoking consent. Expiring a connection is a
     * one-way door — the only way out is a full reauthorization with SCA — so
     * this must not expire one on first sight. If it turns out to be genuine,
     * the consent lapses on its own and 401 EXPIRED_SESSION takes over.
     */
    private function isPsuActionRequired(RequestException $e): bool
    {
        return $e->response->status() === 403
            && $this->detailErrorName($e) === 'PsuActionRequiredException';
    }

    private function isInaccessibleAccount(RequestException $e): bool
    {
        // ponytail: the documented per-account 400; widen if other terminal
        // account-level codes surface for a single account.
        return $e->response->status() === 400
            && $this->detailErrorName($e) === 'AccountNotAccessibleException';
    }

    private function detailErrorName(RequestException $e): ?string
    {
        $detail = $this->errorBody($e)['detail'] ?? null;

        return is_array($detail) ? ($detail['error_name'] ?? null) : null;
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
                    || $this->isPsuActionRequired($exception)
                    || $response->status() === 422;

                Log::log($isExpected ? 'warning' : 'error', 'EnableBanking API error', [
                    'status' => $response->status(),
                    // Which endpoint failed tells one bad account apart from a
                    // whole connection or a provider-wide wave.
                    'path' => $response->effectiveUri()?->getPath(),
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
