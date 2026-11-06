<?php

namespace App\Enums;

use App\Actions\CreateDefaultCategories;

/**
 * The main monthly expenses someone with no file at hand can type in during
 * onboarding, each one stored as an estimated transaction under the default
 * category it belongs to.
 */
enum QuickExpense: string
{
    case Rent = 'rent';
    case Mortgage = 'mortgage';
    case Groceries = 'groceries';
    case Utilities = 'utilities';
    case Subscriptions = 'subscriptions';
    case Transport = 'transport';
    case EatingOut = 'eating_out';
    case Insurance = 'insurance';

    /**
     * The default category it is filed under, by its canonical English name
     * (see {@see CreateDefaultCategories}).
     */
    public function defaultCategory(): string
    {
        return match ($this) {
            self::Rent => 'Rent and maintanence',
            self::Mortgage => 'Mortgage',
            self::Groceries => 'Groceries',
            self::Utilities => 'Utility services',
            self::Subscriptions => 'Online services',
            self::Transport => 'Transportation',
            self::EatingOut => 'Cafes, restaurants, bars',
            self::Insurance => 'Insurance',
        };
    }

    /**
     * The transaction's description, as an English source string to translate.
     */
    public function description(): string
    {
        return match ($this) {
            self::Rent => 'Rent',
            self::Mortgage => 'Mortgage',
            self::Groceries => 'Groceries',
            self::Utilities => 'Utilities',
            self::Subscriptions => 'Subscriptions',
            self::Transport => 'Transport',
            self::EatingOut => 'Eating out',
            self::Insurance => 'Insurance',
        };
    }
}
