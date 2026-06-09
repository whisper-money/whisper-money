<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\BankingProviderInterface;
use App\Services\Banking\EnableBankingProvider;

/**
 * In-memory stand-in for {@see EnableBankingProvider} used by
 * browser tests. It mirrors the Enable Banking response shapes so the full UI →
 * authorize → callback → mapping → sync flow can be exercised in CI without hitting
 * the live sandbox.
 *
 * The connect flow spans several HTTP requests handled by the same in-process kernel,
 * so this instance is registered as a container singleton and keeps its state (the
 * pending authorization plus stable IBANs) between calls. New session/account UIDs are
 * minted on every {@see createSession()} call, exactly like Enable Banking does, so the
 * reconnect path that re-matches accounts by IBAN is covered too.
 */
final class FakeBankingProvider implements BankingProviderInterface
{
    /**
     * The authorization started by the most recent startAuthorization() call.
     *
     * @var array{aspsp_name: string, country: string, state: string}|null
     */
    private ?array $pendingAuthorization = null;

    private int $sessionCounter = 0;

    /**
     * Stable IBANs per institution, so reconnects re-match existing accounts by IBAN.
     *
     * @var array<string, list<string>>
     */
    private array $ibansByAspsp = [
        'Banco de Sabadell' => ['ES1800810602610001111120', 'ES6200810602620003333338'],
        'BBVA' => ['ES2100750000000000000001', 'ES2100750000000000000002'],
    ];

    /**
     * {@inheritDoc}
     */
    public function getInstitutions(string $countryCode): array
    {
        return [
            ['name' => 'Banco de Sabadell', 'country' => $countryCode, 'logo' => null, 'maximum_consent_validity' => 7776000],
            ['name' => 'BBVA', 'country' => $countryCode, 'logo' => null, 'maximum_consent_validity' => 7776000],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function startAuthorization(string $aspspName, string $countryCode, string $redirectUrl, string $state): array
    {
        $this->pendingAuthorization = [
            'aspsp_name' => $aspspName,
            'country' => $countryCode,
            'state' => $state,
        ];

        // Skip the real bank redirect: send the browser straight back to our own
        // callback on the current host (the test server) with a synthetic code+state.
        // route() resolves to the running browser-test server, so the flow stays
        // in-process instead of navigating to the configured production redirect host.
        return [
            'url' => route('open-banking.callback', ['code' => 'fake-code-'.$state, 'state' => $state]),
            'authorization_id' => 'fake-auth-'.$state,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function createSession(string $code): array
    {
        $this->sessionCounter++;

        $aspspName = $this->pendingAuthorization['aspsp_name'] ?? 'Banco de Sabadell';
        $country = $this->pendingAuthorization['country'] ?? 'ES';
        $ibans = $this->ibansByAspsp[$aspspName] ?? ['ES0000000000000000000001'];

        $accounts = [];
        foreach ($ibans as $index => $iban) {
            $accounts[] = [
                'uid' => sprintf('fake-uid-%d-%d', $this->sessionCounter, $index),
                'currency' => 'EUR',
                'name' => sprintf('%s account %d', $aspspName, $index + 1),
                'account_id' => ['iban' => $iban],
            ];
        }

        return [
            'session_id' => 'fake-session-'.$this->sessionCounter,
            'aspsp' => ['name' => $aspspName, 'country' => $country],
            'accounts' => $accounts,
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getTransactions(string $accountId, string $dateFrom, string $dateTo, ?string $continuationKey = null, ?string $strategy = null): array
    {
        return ['transactions' => [], 'continuation_key' => null];
    }

    /**
     * {@inheritDoc}
     */
    public function getBalances(string $accountId): array
    {
        return ['balances' => []];
    }

    /**
     * {@inheritDoc}
     */
    public function getSession(string $sessionId): array
    {
        return [
            'status' => 'AUTHORIZED',
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
            'accounts' => [],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getAccount(string $accountId): array
    {
        return [
            'uid' => $accountId,
            'account_id' => ['iban' => 'ES0000000000000000000000'],
            'currency' => 'EUR',
            'name' => 'Fake account',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function revokeSession(string $sessionId): void
    {
        // No-op: nothing to revoke for the in-memory fake.
    }
}
