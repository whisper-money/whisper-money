<?php

namespace App\Contracts;

use App\Models\BankingConnection;

interface BankingConnectionSyncer
{
    /**
     * Sync every account belonging to the connection.
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
