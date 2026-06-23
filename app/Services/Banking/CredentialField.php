<?php

namespace App\Services\Banking;

/**
 * Describes one credential input for an API-key banking provider: the request
 * field name, the encrypted connection column it is stored in, and its
 * validation rules.
 *
 * Centralizes the credential shape so it lives in one place (the
 * BankingProvider enum) instead of being duplicated across each connect
 * controller, the update request and the update controller.
 */
final class CredentialField
{
    /**
     * @param  array<int, mixed>  $rules
     */
    public function __construct(
        public string $input,
        public string $column,
        public array $rules,
    ) {}
}
