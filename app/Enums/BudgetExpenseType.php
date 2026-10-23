<?php

namespace App\Enums;

/**
 * Whether a budget covers spending that repeats at the same amount every
 * period (rent, subscriptions) or spending the user can adjust (groceries,
 * restaurants). Cashflow splits expenses by it.
 */
enum BudgetExpenseType: string
{
    case Fixed = 'fixed';
    case Variable = 'variable';
}
