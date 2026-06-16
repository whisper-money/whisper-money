<?php

namespace App\Services\Banking\Sync;

use App\Contracts\BankingConnectionSyncer;

/**
 * Sensible defaults for the common provider shape: an API-key integration that
 * never expires and notifies the user when its credentials stop working.
 *
 * Consent-based providers override expires(); providers that authenticate
 * without user-managed credentials override notifiesOnAuthFailure().
 */
abstract class AbstractBankingConnectionSyncer implements BankingConnectionSyncer
{
    public function expires(): bool
    {
        return false;
    }

    public function notifiesOnAuthFailure(): bool
    {
        return true;
    }
}
