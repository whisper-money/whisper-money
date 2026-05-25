import { Category } from '@/types/category';
import { endOfMonth, format, startOfMonth } from 'date-fns';
import { useCallback, useEffect, useState } from 'react';

export type CashflowPeriodType = 'month' | 'quarter' | 'year';

export interface CashflowSummary {
    income: number;
    expense: number;
    net: number;
    savings_rate: number;
    savings: number;
    investments: number;
}

export interface SankeyCategory {
    category: Category;
    category_id: string;
    amount: number;
}

export interface SankeyData {
    income_categories: SankeyCategory[];
    expense_categories: SankeyCategory[];
    total_income: number;
    total_expense: number;
}

export interface TrendDataPoint {
    month: string;
    income: number;
    expense: number;
    net: number;
}

export interface BreakdownItem {
    category: Category | null;
    category_id: string | null;
    amount: number;
    percentage: number;
    previous_amount: number;
}

export interface BreakdownData {
    data: BreakdownItem[];
    total: number;
    previous_total: number;
}

export interface CashflowData {
    summary: {
        current: CashflowSummary;
        previous: CashflowSummary;
    };
    sankey: SankeyData;
    trend: TrendDataPoint[];
    incomeBreakdown: BreakdownData;
    expenseBreakdown: BreakdownData;
    isLoading: boolean;
}

interface UseCashflowDataOptions {
    from: Date;
    to: Date;
    periodType: CashflowPeriodType;
}

const emptyBreakdown: BreakdownData = {
    data: [],
    total: 0,
    previous_total: 0,
};

const emptySummary: CashflowSummary = {
    income: 0,
    expense: 0,
    net: 0,
    savings_rate: 0,
    savings: 0,
    investments: 0,
};

export function useCashflowData({
    from,
    to,
    periodType,
}: UseCashflowDataOptions): CashflowData & { refetch: () => void } {
    const [data, setData] = useState<Omit<CashflowData, 'isLoading'>>({
        summary: { current: emptySummary, previous: emptySummary },
        sankey: {
            income_categories: [],
            expense_categories: [],
            total_income: 0,
            total_expense: 0,
        },
        trend: [],
        incomeBreakdown: emptyBreakdown,
        expenseBreakdown: emptyBreakdown,
    });
    const [isLoading, setIsLoading] = useState(true);

    const fetchData = useCallback(async () => {
        setIsLoading(true);
        try {
            const fromStr = format(from, 'yyyy-MM-dd');
            const toStr = format(to, 'yyyy-MM-dd');

            const periodParams = new URLSearchParams({
                from: fromStr,
                to: toStr,
            });
            const periodQuery = `?${periodParams.toString()}`;
            const trendQuery =
                periodType === 'month' ? `?months=12&to=${toStr}` : periodQuery;

            const [summary, sankey, trend, incomeBreakdown, expenseBreakdown] =
                await Promise.all([
                    fetch(`/api/cashflow/summary${periodQuery}`).then((r) =>
                        r.json(),
                    ),
                    fetch(`/api/cashflow/sankey${periodQuery}`).then((r) =>
                        r.json(),
                    ),
                    fetch(`/api/cashflow/trend${trendQuery}`).then((r) =>
                        r.json(),
                    ),
                    fetch(
                        `/api/cashflow/breakdown${periodQuery}&type=income`,
                    ).then((r) => r.json()),
                    fetch(
                        `/api/cashflow/breakdown${periodQuery}&type=expense`,
                    ).then((r) => r.json()),
                ]);

            setData({
                summary,
                sankey,
                trend: trend.data,
                incomeBreakdown,
                expenseBreakdown,
            });
        } catch (error) {
            console.error('Failed to fetch cashflow data:', error);
        } finally {
            setIsLoading(false);
        }
    }, [from, periodType, to]);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

    return { ...data, isLoading, refetch: fetchData };
}

export function getDefaultPeriod(): { from: Date; to: Date } {
    const now = new Date();
    return {
        from: startOfMonth(now),
        to: endOfMonth(now),
    };
}
