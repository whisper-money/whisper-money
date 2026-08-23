import {
    breakdown as cashflowBreakdown,
    sankey as cashflowSankey,
    summary as cashflowSummary,
    trend as cashflowTrend,
} from '@/actions/App/Http/Controllers/Api/CashflowAnalyticsController';
import { fetchJson } from '@/lib/fetch-json';
import { Category } from '@/types/category';
import { __ } from '@/utils/i18n';
import { endOfMonth, format, startOfMonth } from 'date-fns';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';

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
    has_children?: boolean;
    is_direct?: boolean;
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
    has_children?: boolean;
    is_direct?: boolean;
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

            const periodQuery = { from: fromStr, to: toStr };
            const trendQuery =
                periodType === 'month'
                    ? { months: 12, to: toStr }
                    : periodQuery;

            const [summary, sankey, trend, incomeBreakdown, expenseBreakdown] =
                await Promise.all([
                    fetchJson<CashflowData['summary']>(
                        cashflowSummary.url({ query: periodQuery }),
                    ),
                    fetchJson<CashflowData['sankey']>(
                        cashflowSankey.url({ query: periodQuery }),
                    ),
                    fetchJson<{ data: CashflowData['trend'] }>(
                        cashflowTrend.url({ query: trendQuery }),
                    ),
                    fetchJson<CashflowData['incomeBreakdown']>(
                        cashflowBreakdown.url({
                            query: { ...periodQuery, type: 'income' },
                        }),
                    ),
                    fetchJson<CashflowData['expenseBreakdown']>(
                        cashflowBreakdown.url({
                            query: { ...periodQuery, type: 'expense' },
                        }),
                    ),
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

            // The cards below keep whatever they had, which on a first load is a
            // row of zeros - so without this the screen states, flatly, that the
            // period had no money in it.
            toast.error(
                __('We could not load these figures. Try again in a moment.'),
                {
                    id: 'analytics-load-failed',
                },
            );
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
