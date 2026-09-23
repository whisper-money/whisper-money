<?php

namespace App\Services\Banking;

use App\Enums\BankingProvider;
use App\Exceptions\Banking\MissingProviderPermissionException;
use App\Exceptions\Banking\TransientBankingProviderException;
use App\Services\Banking\Concerns\TranslatesTransportFailures;
use Exception;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

class KrakenClient
{
    use TranslatesTransportFailures;

    private const BASE_URL = 'https://api.kraken.com';

    /** @var array<int, int> Retry backoff: 10s, 30s, 60s */
    private const RETRY_BACKOFF_MS = [10_000, 30_000, 60_000];

    /** Kraken's fixed Ledgers page size. */
    private const LEDGER_PAGE_SIZE = 50;

    private const AUTH_ERRORS = ['EAPI:Invalid key', 'EAPI:Invalid signature', 'EGeneral:Permission denied'];

    private const RATE_LIMIT_ERRORS = ['EAPI:Rate limit exceeded', 'EGeneral:Too many requests'];

    private const PERMISSION_DENIED = 'EGeneral:Permission denied';

    /** Last nonce handed out in this process: Kraken rejects one that does not increase. */
    private static int $lastNonce = 0;

    public function __construct(
        private string $apiKey,
        private string $apiSecret,
    ) {}

    /**
     * Asset balances keyed by Kraken asset code (e.g. XXBT, ZEUR, DOT.S).
     * Needs the "Query Funds" permission.
     *
     * @return array<string, string>
     */
    public function getBalance(): array
    {
        return $this->privateRequest('/0/private/Balance');
    }

    /**
     * Every ledger entry of one type after `$after` (exclusive, unix seconds), walking
     * all pages. Needs the "Query Ledger Entries" permission.
     *
     * @return array<string, array{refid: string, time: float, type: string, subtype: string, asset: string, amount: string, fee: string}>
     */
    public function getLedgerEntries(string $type, ?int $after = null): array
    {
        $entries = [];
        $offset = 0;

        do {
            $page = $this->getLedgerPage($type, $after, $offset);
            $rows = $page['ledger'] ?? [];
            $entries += $rows;
            $offset += count($rows);
        } while (count($rows) === self::LEDGER_PAGE_SIZE && $offset < (int) ($page['count'] ?? 0));

        return $entries;
    }

    /**
     * Last traded price of every pair, keyed by pair name (XXBTZEUR, DOTEUR, …).
     * Public endpoint — no authentication.
     *
     * @return array<string, float>
     */
    public function getTickerPrices(): array
    {
        $result = $this->request(fn () => $this->client()->get('/0/public/Ticker'));

        return array_map(fn (array $ticker) => (float) $ticker['c'][0], $result);
    }

    /**
     * Check the key carries both permissions the sync needs, naming the one
     * that is missing. Invalid keys still surface as a 401 RequestException.
     */
    public function verifyPermissions(): void
    {
        $this->requirePermission('Query Funds', fn () => $this->getBalance());
        $this->requirePermission('Query Ledger Entries', fn () => $this->getLedgerPage('deposit', null, 0));
    }

    protected function provider(): BankingProvider
    {
        return BankingProvider::Kraken;
    }

    /**
     * @return array{ledger?: array<string, array<string, mixed>>, count?: int}
     */
    private function getLedgerPage(string $type, ?int $after, int $offset): array
    {
        return $this->privateRequest('/0/private/Ledgers', array_filter([
            'type' => $type,
            'start' => $after,
            'ofs' => $offset,
        ], fn ($value) => $value !== null));
    }

    private function requirePermission(string $permission, callable $call): void
    {
        try {
            $call();
        } catch (RequestException $e) {
            if (str_contains($e->response->body(), self::PERMISSION_DENIED)) {
                throw new MissingProviderPermissionException(
                    __('Your Kraken API key is missing the ":permission" permission. Enable it and try again.', ['permission' => $permission]),
                    previous: $e,
                );
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, int|string>  $params
     */
    private function privateRequest(string $path, array $params = []): array
    {
        return $this->request(function () use ($path, $params) {
            $nonce = $this->nextNonce();
            $body = http_build_query(['nonce' => $nonce, ...$params]);

            return $this->client()
                ->withHeaders([
                    'API-Key' => $this->apiKey,
                    'API-Sign' => $this->sign($path, $nonce, $body),
                ])
                ->withBody($body, 'application/x-www-form-urlencoded')
                ->post($path);
        });
    }

    /**
     * Run a request with the 429 backoff, unwrapping Kraken's `result`. The
     * signed body is rebuilt on every attempt so each retry gets a fresh nonce.
     *
     * @param  callable(): Response  $send
     */
    private function request(callable $send): array
    {
        return $this->translateTransportFailures(fn () => retry(
            self::RETRY_BACKOFF_MS,
            function () use ($send) {
                $response = $send();

                $response->throw();

                $this->throwForErrors($response->json('error') ?? []);

                return $response->json('result') ?? [];
            },
            when: fn (Exception $e) => $e instanceof RequestException && $e->response->status() === 429,
        ));
    }

    /**
     * Kraken answers most failures with a 200 and an `error` list. Map them onto
     * the statuses the sync job acts on: auth → 401, rate limit → 429 (thrown
     * inside the retry so the backoff applies), outages → transient.
     *
     * @param  array<int, string>  $errors
     */
    private function throwForErrors(array $errors): void
    {
        if ($errors === []) {
            return;
        }

        $message = implode('; ', $errors);

        Log::warning('Kraken API error', ['errors' => $errors]);

        $status = match (true) {
            array_intersect($errors, self::RATE_LIMIT_ERRORS) !== [] => 429,
            array_intersect($errors, self::AUTH_ERRORS) !== [] => 401,
            default => null,
        };

        if ($status !== null) {
            throw new RequestException(new Response(new PsrResponse($status, [], $message)));
        }

        if ($this->isOutage($errors)) {
            throw new TransientBankingProviderException(
                "Kraken could not serve the request right now: {$message}",
                provider: $this->provider()->value,
                providerCode: $errors[0],
            );
        }

        throw new RequestException(new Response(new PsrResponse(400, [], $message)));
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function isOutage(array $errors): bool
    {
        foreach ($errors as $error) {
            if (str_starts_with($error, 'EService:') || $error === 'EGeneral:Internal error') {
                return true;
            }
        }

        return false;
    }

    /**
     * API-Sign = base64(HMAC-SHA512(path . SHA256(nonce . body), base64_decode(secret))).
     */
    private function sign(string $path, int $nonce, string $body): string
    {
        $digest = hash('sha256', $nonce.$body, true);

        return base64_encode(hash_hmac('sha512', $path.$digest, base64_decode($this->apiSecret), true));
    }

    /**
     * Microseconds since the epoch, bumped when two requests land in the same one.
     */
    private function nextNonce(): int
    {
        self::$lastNonce = max(self::$lastNonce + 1, (int) (microtime(true) * 1_000_000));

        return self::$lastNonce;
    }

    private function client(): PendingRequest
    {
        return $this->transportClient(self::BASE_URL)
            ->acceptJson()
            ->throw(function ($response, $exception) {
                Log::log($response->serverError() ? 'warning' : 'error', 'Kraken API error', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
            });
    }
}
