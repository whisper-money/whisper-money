<?php

namespace App\Contracts;

use App\Models\BankingConnection;

interface BankingConnectionSyncer
{
    /**
     * Sync every account belonging to the connection.
     *
     * The one key the job acts on rather than just persisting is
     * `balance_rate_limit`: `array{retry_after: string|null, message: string}`
     * when the provider rate limited a balance call but the run is still worth
     * keeping, and the job turns it into the connection's backoff window.
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
