<?php

namespace App\Services\Banking;

use App\Contracts\BankingProviderInterface;
use App\Exceptions\Banking\BankingRequestException;
use App\Exceptions\Banking\ExpiredBankingSessionException;
use App\Exceptions\Banking\InaccessibleBankAccountException;
use App\Exceptions\Banking\TransientBankingProviderException;
use App\Exceptions\Banking\WrongTransactionsPeriodException;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EnableBankingProvider implements BankingProviderInterface
{
    private const BASE_URL = 'https://api.enablebanking.com';

    /**
     * The widest transactions window a bank is assumed to serve unattended, and
     * the first rung of `TransactionSyncService`'s narrowing ladder. Anything
     * wider is what a bank connector's opaque refusal is read as being about.
     *
     * It has to stay equal to that first rung, or the retry lands on a window
     * still counted as wide and the ladder walks all three steps at a bank's
     * expense. TransactionSyncServiceTest pins the two together by counting the
     * requests one opaque refusal costs, so drifting them apart fails there.
     */
    private const int WIDE_WINDOW_DAYS = 90;

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
                // The provider's own reliability signal, and by a wide margin
                // the best one we have: over a week of calls, its beta
                // connectors failed eleven times as often as the stable ones,
                // and they are left out of the provider's public status page,
                // so nobody reports their outages either.
                'beta' => (bool) ($aspsp['beta'] ?? false),
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
                operation: 'transactions',
                previous: $e,
            );
        } catch (RequestException $e) {
            if ($this->isWrongPeriod($e) || $this->isRefusedWideWindow($e, $dateFrom, $dateTo)) {
                throw new WrongTransactionsPeriodException(
                    'EnableBanking rejected the requested transactions period as too wide.',
                    previous: $e,
                );
            }

            $this->rethrowRequestFailure($e, 'transactions');
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
                operation: 'balances',
                previous: $e,
            );
        } catch (RequestException $e) {
            $this->rethrowRequestFailure($e, 'balances');
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

    /**
     * Re-raise a failed EnableBanking request as the classified exception the
     * caller expects, tagged with the operation it came from.
     *
     * The tag is the whole point. A 429 matches none of the branches below and
     * leaves as a plain request failure, which is why nothing downstream has
     * ever been able to say whether it is balances or transactions that hits
     * the limit.
     */
    private function rethrowRequestFailure(RequestException $e, string $operation): never
    {
        if ($this->requiresReconnect($e)) {
            throw new ExpiredBankingSessionException(
                "EnableBanking needs the user to reconnect before it will serve account {$operation}.",
                previous: $e,
            );
        }

        if ($this->isInaccessibleAccount($e)) {
            throw new InaccessibleBankAccountException(
                "EnableBanking account is no longer accessible while fetching {$operation}.",
                previous: $e,
            );
        }

        if ($this->isTransientServerError($e) || $this->isPsuActionRequired($e)) {
            throw new TransientBankingProviderException(
                "EnableBanking could not serve account {$operation} right now.",
                provider: 'enablebanking',
                statusCode: $e->response->status(),
                providerCode: $this->detailErrorName($e),
                operation: $operation,
                previous: $e,
            );
        }

        if ($this->isAspspError($e)) {
            $providerCode = $this->errorBody($e)['error'] ?? null;

            throw new TransientBankingProviderException(
                "EnableBanking bank connector failed while fetching account {$operation}.",
                provider: 'enablebanking',
                statusCode: $e->response->status(),
                providerCode: is_string($providerCode) ? $providerCode : null,
                operation: $operation,
                previous: $e,
            );
        }

        throw new BankingRequestException($e->response, $operation);
    }

    /**
     * Whether a failed request is one an unattended sync should expect.
     *
     * A flaky bank connector, a consent the user has to renew, a period the bank
     * won't serve, a quota the bank meters: the caller handles all of these, so
     * they belong in the log as warnings rather than as application errors.
     * Reported as errors, the metered quota alone was 120 of the 134 error-level
     * entries a day - enough to bury the ones that are real, as it did for three
     * nights while a scheduled command was dying with nothing but one line in
     * there to show for it.
     *
     * This is not a claim that a 429 is always handled well. The backoff that
     * answers one is only partly effective, and deliberately so:
     * `isExhaustedAccessAllowance` parks the connection until midnight for the
     * daily-allowance wordings, while a bare "Too many requests" gets an hour
     * against a six-hourly schedule, so that park usually expires unused.
     * Widening the match to cover it was tried and dropped, because 1.7% of
     * those retries do recover and a day-long park would forfeit them. The claim
     * is only that a metered quota is never an application defect.
     *
     * An upstream 5xx is not on the list. That is the provider failing rather
     * than the provider working as designed, and nothing here can answer it.
     *
     * Note this also covers the interactive paths - the bank picker and the
     * authorization flow - where nothing parks anything and a user is watching.
     * Those let the exception escape unwrapped, so it still reaches Sentry.
     */
    private function isExpectedProviderOutcome(RequestException $e): bool
    {
        return $this->isAspspError($e)
            || $this->requiresReconnect($e)
            || $this->isPsuActionRequired($e)
            || $this->isRateLimited($e)
            // Narrowed to the window refusal rather than every 422, because
            // `isWrongPeriod` exists to let a genuine validation 422 - a
            // malformed date, say - surface, and downgrading all of them here
            // was quietly defeating that. Inert today: production has not seen a
            // 422 from this provider in a fortnight.
            || $this->isWrongPeriod($e);
    }

    private function isRateLimited(RequestException $e): bool
    {
        return $e->response->status() === 429;
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
        // so retry and self-heal rather than fail the connection over it. Note
        // that it does still log as an error, unlike the outcomes in
        // `isExpectedProviderOutcome`: the caller recovering from a provider
        // outage is not the same as the outage being expected.
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
     * Whether a bank connector that failed opaquely was answering a window
     * wider than banks behind Redsys will serve.
     *
     * Renta 4 sits behind Redsys, which refuses a 365-day range with
     * `EXECUTION_DATE_INVALID` - but that code only ever appears in the ASPSP
     * half of the provider's request log. What reaches us, verified against
     * production on 2026-08-22, is `{"code":400,"message":"Error interacting
     * with ASPSP","detail":"Unknown error","error":"ASPSP_ERROR"}`: nothing
     * bank-specific to key on. So the width of the window we asked for is the
     * signal, and narrowing to 90 days is the cheapest way to find out.
     *
     * Self-bounding, which is the point: 90 days is the ladder's first rung and
     * a 90-day window is not wider than 90 days, so the retry can only classify
     * as an ordinary connector failure. A bank that 400s for an unrelated
     * reason therefore costs one extra request against its allowance, not three.
     */
    private function isRefusedWideWindow(RequestException $e, string $dateFrom, string $dateTo): bool
    {
        // Dates are 'Y-m-d', where string order is date order.
        return $this->isAspspError($e)
            && $dateFrom < Carbon::parse($dateTo)->subDays(self::WIDE_WINDOW_DAYS)->toDateString();
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
                Log::log($this->isExpectedProviderOutcome($exception) ? 'warning' : 'error', 'EnableBanking API error', [
                    'status' => $response->status(),
                    // Which endpoint failed tells one bad account apart from a
                    // whole connection or a provider-wide wave.
                    'path' => $this->redactedPath($response->effectiveUri()?->getPath()),
                    // Capped like every other body this project logs: an error
                    // body is provider boilerplate today, but nothing guarantees
                    // a bank will not answer with account data on an error status.
                    'body' => Str::limit($response->body(), 500),
                    'exception' => get_class($exception),
                ]);
            });
    }

    /**
     * The endpoint that failed, with the bank's own identifiers stripped out.
     *
     * The shape of the path is what has diagnostic value; the account and
     * session ids in it are a bank's handle on a real person's account, and
     * these logs leave the building. Keep the shape, drop the ids.
     */
    private function redactedPath(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        return preg_replace('#/(accounts|sessions)/[^/]+#', '/$1/{id}', $path);
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
