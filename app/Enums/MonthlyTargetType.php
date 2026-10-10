<?php

namespace App\Enums;

/**
 * How a monthly savings goal sets its target: a fixed amount every month, or a
 * share of the income the user usually earns.
 */
enum MonthlyTargetType: string
{
    case Amount = 'amount';
    case IncomeRate = 'income_rate';
}
