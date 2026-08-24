<?php

namespace App\Enums;

use App\Services\Banking\CredentialField;

enum BankingProvider: string
{
    case IndexaCapital = 'indexacapital';
    case Binance = 'binance';
    case Bitpanda = 'bitpanda';
    case Coinbase = 'coinbase';
    case InteractiveBrokers = 'interactivebrokers';
    case Wise = 'wise';
    case EnableBanking = 'enablebanking';

    /**
     * Whether the provider authenticates with user-supplied API keys
     * rather than EnableBanking's hosted OAuth flow.
     */
    public function usesApiKey(): bool
    {
        return $this !== self::EnableBanking;
    }

    /**
     * The account type that this provider's pending accounts default to.
     */
    public function defaultAccountType(): AccountType
    {
        return match ($this) {
            self::IndexaCapital, self::Binance, self::Bitpanda, self::Coinbase, self::InteractiveBrokers => AccountType::Investment,
            self::Wise, self::EnableBanking => AccountType::Checking,
        };
    }

    /**
     * The credential inputs this provider collects, each mapped to the
     * encrypted connection column it is stored in, with its validation rules.
     *
     * Single source of truth for credential shape: the connect controllers,
     * the update request and the update controller all derive from this so a
     * new provider is described in exactly one place. Empty for consent-based
     * providers that authenticate without user-supplied credentials.
     *
     * @return array<int, CredentialField>
     */
    public function credentialFields(): array
    {
        return match ($this) {
            self::IndexaCapital, self::Wise => [
                new CredentialField('api_token', 'api_token', ['required', 'string', 'min:10']),
            ],
            self::Binance => [
                new CredentialField('api_key', 'api_token', ['required', 'string', 'min:10']),
                new CredentialField('api_secret', 'api_secret', ['required', 'string', 'min:10']),
            ],
            self::Bitpanda => [
                new CredentialField('api_key', 'api_token', ['required', 'string', 'min:10']),
            ],
            self::Coinbase => [
                new CredentialField('api_key_name', 'api_token', ['required', 'string', 'regex:/^(organizations\/[a-z0-9-]+\/apiKeys\/[a-z0-9-]+|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/i']),
                new CredentialField('private_key', 'api_secret', ['required', 'string', 'min:40']),
            ],
            self::InteractiveBrokers => [
                new CredentialField('token', 'api_token', ['required', 'string', 'min:10']),
                new CredentialField('query_id', 'api_secret', ['required', 'string', 'min:3']),
            ],
            self::EnableBanking => [],
        };
    }

    /**
     * Validation rules for this provider's credential inputs, keyed by input
     * name. Shared by the connect Form Requests and the update request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function credentialRules(): array
    {
        $rules = [];

        foreach ($this->credentialFields() as $field) {
            $rules[$field->input] = $field->rules;
        }

        return $rules;
    }

    /**
     * Map validated request input to the encrypted connection columns.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function credentialColumns(array $input): array
    {
        $columns = [];

        foreach ($this->credentialFields() as $field) {
            $columns[$field->column] = $input[$field->input];
        }

        return $columns;
    }
}
