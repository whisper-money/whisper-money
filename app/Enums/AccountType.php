<?php

namespace App\Enums;

enum AccountType: string
{
    case Checking = 'checking';
    case CreditCard = 'credit_card';
    case Investment = 'investment';
    case Loan = 'loan';
    case Retirement = 'retirement';
    case RealEstate = 'real_estate';
    case Savings = 'savings';
    case Others = 'others';

    /**
     * Whether this account type supports tracking invested amount and gains/losses.
     */
    public function supportsInvestedAmount(): bool
    {
        return in_array($this, [self::Investment, self::Retirement, self::Savings], true);
    }

    public function reducesNetWorth(): bool
    {
        return in_array($this, [self::CreditCard, self::Loan], true);
    }

    /**
     * Whether this account type is non-transactional (balance tracking only).
     */
    public function isNonTransactional(): bool
    {
        return in_array($this, [self::Investment, self::Retirement, self::RealEstate], true);
    }

    /**
     * Whether a bank connection can sync transactions into this account type.
     * Excludes balance/value-tracking types (loan, investment, retirement, real estate).
     */
    public function canSyncBankTransactions(): bool
    {
        return in_array($this, [self::Checking, self::CreditCard, self::Savings, self::Others], true);
    }
}
