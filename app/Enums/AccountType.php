<?php

namespace App\Enums;

enum AccountType: string
{
    case Checking = 'checking';
    case CreditCard = 'credit_card';
    case Investment = 'investment';
    case Loan = 'loan';
    case Retirement = 'retirement';
    case Savings = 'savings';
    case Others = 'others';

    /**
     * Whether this account type supports tracking invested amount and gains/losses.
     */
    public function supportsInvestedAmount(): bool
    {
        return in_array($this, [self::Investment, self::Retirement, self::Savings], true);
    }
}
