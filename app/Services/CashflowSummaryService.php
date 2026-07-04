<?php

namespace App\Services;

class CashflowSummaryService
{
    /**
     * Derive the shared cashflow summary from already-clamped income and
     * expense totals (both non-negative, in minor units). Kept in one place so
     * the net and savings-rate math can never drift between the dashboard and
     * the cashflow analytics endpoints.
     *
     * @return array{income: int, expense: int, net: int, savings_rate: float|int}
     */
    public static function summarize(int $income, int $expense): array
    {
        return [
            'income' => $income,
            'expense' => $expense,
            'net' => $income - $expense,
            'savings_rate' => $income > 0 ? round((($income - $expense) / $income) * 100, 1) : 0,
        ];
    }
}
