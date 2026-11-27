<?php

namespace App\Services\Forecast\Backtest;

/**
 * What a backtest observation compares. Balances are month closes; category
 * figures are what one row moved in one month.
 */
final class BacktestSeries
{
    public const ACCOUNT = 'Liquid account close · forecast';

    public const ACCOUNT_FLAT = 'Liquid account close · flat balance';

    public const ACCOUNT_NET_FLOW = 'Liquid account close · 3-month average net flow';

    public const TOTAL = 'All-accounts total · forecast';

    public const TOTAL_FLAT = 'All-accounts total · flat balance';

    public const TOTAL_NET_FLOW = 'All-accounts total · 3-month average net flow';

    public const INCOME_CATEGORY = 'Income · per category and month';

    public const EXPENSE_CATEGORY = 'Spend · per category and month';

    public const INCOME_MONTH = 'Income · account total per month';

    public const EXPENSE_MONTH = 'Spend · account total per month';

    /**
     * The balance series, in the order they are reported.
     *
     * @var list<string>
     */
    public const BALANCES = [self::ACCOUNT, self::ACCOUNT_FLAT, self::ACCOUNT_NET_FLOW, self::TOTAL, self::TOTAL_FLAT, self::TOTAL_NET_FLOW];

    /**
     * The flow series, in the order they are reported.
     *
     * @var list<string>
     */
    public const FLOWS = [self::INCOME_CATEGORY, self::EXPENSE_CATEGORY, self::INCOME_MONTH, self::EXPENSE_MONTH];
}
