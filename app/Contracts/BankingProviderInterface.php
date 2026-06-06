<?php

namespace App\Contracts;

interface BankingProviderInterface
{
    /**
     * Get available banking institutions for a country.
     *
     * @return array<int, array{name: string, country: string, logo: string|null, maximum_consent_validity: int|null}>
     */
    public function getInstitutions(string $countryCode): array;

    /**
     * Start a user authorization flow.
     *
     * The $state value is echoed back by the provider on the callback and is used to
     * correlate the callback to its connection without relying on a logged-in session.
     *
     * @return array{url: string, authorization_id: string}
     */
    public function startAuthorization(string $aspspName, string $countryCode, string $redirectUrl, string $state): array;

    /**
     * Exchange a callback code for a session with accounts.
     *
     * @return array{session_id: string, accounts: array, aspsp: array, access: array}
     */
    public function createSession(string $code): array;

    /**
     * Fetch transactions for an account.
     *
     * @return array{transactions: array, continuation_key: string|null}
     */
    public function getTransactions(string $accountId, string $dateFrom, string $dateTo, ?string $continuationKey = null, ?string $strategy = null): array;

    /**
     * Fetch balances for an account.
     *
     * @return array{balances: array}
     */
    public function getBalances(string $accountId): array;

    /**
     * Get session details and status.
     *
     * @return array{status: string, access: array, accounts: array}
     */
    public function getSession(string $sessionId): array;

    /**
     * Get details for a single account, including IBAN.
     *
     * @return array{uid: string, account_id: array{iban?: string}, currency: string, name: string|null}
     */
    public function getAccount(string $accountId): array;

    /**
     * Revoke a session and its consent.
     */
    public function revokeSession(string $sessionId): void;
}
