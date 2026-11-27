<?php

namespace App\Enums;

/**
 * Whether an estimate is money expected in (income) or out (spend).
 */
enum ForecastEstimateKind: string
{
    case Income = 'income';
    case Expense = 'expense';

    /**
     * The side a category's money is on: income for an income category,
     * spend for any other.
     */
    public static function forCategoryType(?CategoryType $type): self
    {
        return $type === CategoryType::Income ? self::Income : self::Expense;
    }
}
