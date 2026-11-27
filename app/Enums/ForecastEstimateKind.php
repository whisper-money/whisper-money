<?php

namespace App\Enums;

/**
 * Whether an estimate is money expected in (income) or out (spend).
 */
enum ForecastEstimateKind: string
{
    case Income = 'income';
    case Expense = 'expense';
}
