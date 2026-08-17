<?php

namespace App\Contracts;

use App\Models\BankingConnection;

interface BankingConnectionSyncer
{
    /**
     * Sync every account belonging to the connection.
     *
     * One key is read rather than just logged: `rate_limited_until`, an ISO 8601
     * string, tells SyncBankingConnectionJob the provider has asked us to stay away
     * until then. Return it when the run finished but hit a rate limit on the way -
     * the alternative, throwing, discards a run whose transactions already landed.
     * Omit it and the job clears any existing window, which is what a clean run
     * should do.
     *
     * @return array<string, mixed> Metadata to persist on the sync log.
     */
    public function sync(BankingConnection $connection, bool $isFirstSync): array;

    /**
     * Whether the connection's consent can expire (consent-based providers).
     */
    public function expires(): bool;

    /**
     * Whether a permanent auth failure should notify the user (API-key providers).
     */
    public function notifiesOnAuthFailure(): bool;
}
