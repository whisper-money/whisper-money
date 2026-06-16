<?php

namespace App\Enums;

enum BankingProvider: string
{
    case IndexaCapital = 'indexacapital';
    case Binance = 'binance';
    case Bitpanda = 'bitpanda';
    case Coinbase = 'coinbase';
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
            self::IndexaCapital, self::Binance, self::Bitpanda, self::Coinbase => AccountType::Investment,
            self::Wise, self::EnableBanking => AccountType::Checking,
        };
    }
}
