<?php

namespace App\Enums;

enum CategoryType: string
{
    case Income = 'income';
    case Expense = 'expense';
    case Transfer = 'transfer';
    case Savings = 'savings';
    case Investment = 'investment';

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Income',
            self::Expense => 'Expense',
            self::Transfer => 'Transfer',
            self::Savings => 'Savings',
            self::Investment => 'Investment',
        };
    }

    /** Whether this category sets money aside rather than spending or earning it. */
    public function isSetAside(): bool
    {
        return $this === self::Savings || $this === self::Investment;
    }

    /**
     * The cashflow direction a root category of this type starts with: money
     * set aside leaves the cashflow, everything else stays out of it. Only a
     * transfer lets the user pick another one.
     */
    public function defaultCashflowDirection(): CategoryCashflowDirection
    {
        return $this->isSetAside()
            ? CategoryCashflowDirection::Outflow
            : CategoryCashflowDirection::Hidden;
    }
}
