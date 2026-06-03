import { useLocale } from '@/hooks/use-locale';
import { getAccountSign } from '@/lib/chart-calculations';
import { Account, AccountType, Bank } from '@/types/account';
import { Category } from '@/types/category';
import { formatMonthFromYearMonth } from '@/utils/date';
import { format, subDays, subMonths } from 'date-fns';
import { useCallback, useEffect, useState } from 'react';

export interface NetWorthEvolutionAccount {
    id: string;
    name: string;
    name_iv: string | null;
    encrypted: boolean;
    type: AccountType;
    currency_code: string;
    bank: Bank;
    banking_connection_id: string | null;
    invested_amount?: number | null;
    linked_loan_account_id?: string | null;
}

export interface OriginalAmount {
    amount: number;
    currency_code: string;
}

export interface NetWorthEvolutionData {
    data: Array<Record<string, string | number | OriginalAmount>>;
    accounts: Record<string, NetWorthEvolutionAccount>;
    currency_code: string;
}

export interface AccountWithMetrics extends Account {
    currentBalance: number;
    previousBalance: number;
    diff: number;
    history: Array<{
        date: string;
        value: number;
        investedAmount?: number | null;
    }>;
    investedAmount: number | null;
}

export interface DashboardData {
    netWorthEvolution: NetWorthEvolutionData;
    accounts: AccountWithMetrics[];
    topCategories: Array<{
        category: Category;
        amount: number;
        previous_amount: number;
        total_amount: number;
    }>;
    isLoading: boolean;
}

export function deriveAccountMetrics(
    netWorthEvolution: NetWorthEvolutionData,
    locale = 'en-US',
): AccountWithMetrics[] {
    const { data, accounts } = netWorthEvolution;

    if (data.length === 0 || Object.keys(accounts).length === 0) {
        return [];
    }

    return Object.values(accounts).map((account) => {
        const investedKey = account.id + '_invested';
        const history = data.map((point) => ({
            date: formatMonthFromYearMonth(point.month as string, locale),
            value:
                typeof point[account.id] === 'number'
                    ? getAccountSign(account.type) *
                      Math.abs(point[account.id] as number)
                    : 0,
            investedAmount:
                investedKey in point
                    ? (point[investedKey] as number | null)
                    : undefined,
        }));

        const currentBalance = history[history.length - 1]?.value ?? 0;
        const previousBalance =
            history.length > 1 ? (history[history.length - 2]?.value ?? 0) : 0;

        return {
            id: account.id,
            name: account.name,
            name_iv: account.name_iv,
            type: account.type,
            currency_code: account.currency_code,
            bank: account.bank,
            banking_connection_id: account.banking_connection_id,
            linked_loan_account_id: account.linked_loan_account_id ?? null,
            currentBalance,
            previousBalance,
            diff: currentBalance - previousBalance,
            history,
            investedAmount: account.invested_amount ?? null,
        } as AccountWithMetrics;
    });
}

export function useDashboardData(): DashboardData & { refetch: () => void } {
    const locale = useLocale();
    const [data, setData] = useState<Omit<DashboardData, 'isLoading'>>({
        netWorthEvolution: { data: [], accounts: {}, currency_code: 'USD' },
        accounts: [],
        topCategories: [],
    });
    const [isLoading, setIsLoading] = useState(true);

    const fetchData = useCallback(async () => {
        setIsLoading(true);
        try {
            const now = new Date();
            const to = format(now, 'yyyy-MM-dd');

            const from12Months = format(subMonths(now, 12), 'yyyy-MM-dd');
            const params12Months = new URLSearchParams({
                from: from12Months,
                to,
            });
            const query12Months = `?${params12Months.toString()}`;

            const from30Days = format(subDays(now, 30), 'yyyy-MM-dd');
            const params30Days = new URLSearchParams({
                from: from30Days,
                to,
            });
            const query30Days = `?${params30Days.toString()}`;

            const [netWorthEvolution, topCategories] = await Promise.all([
                fetch(
                    `/api/dashboard/net-worth-evolution${query12Months}`,
                ).then((r) => r.json()),
                fetch(`/api/dashboard/top-categories${query30Days}`).then((r) =>
                    r.json(),
                ),
            ]);

            const netWorthData = netWorthEvolution as NetWorthEvolutionData;

            setData({
                netWorthEvolution: netWorthData,
                accounts: deriveAccountMetrics(netWorthData, locale),
                topCategories,
            });
        } catch (error) {
            console.error('Failed to fetch dashboard data:', error);
        } finally {
            setIsLoading(false);
        }
    }, [locale]);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

    return { ...data, isLoading, refetch: fetchData };
}
