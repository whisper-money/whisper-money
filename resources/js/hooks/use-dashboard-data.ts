import { Account, AccountType, Bank } from '@/types/account';
import { Category } from '@/types/category';
import { format, subDays, subMonths } from 'date-fns';
import { useEffect, useState } from 'react';

export interface NetWorthEvolutionAccount {
    id: string;
    name: string;
    name_iv: string;
    type: AccountType;
    currency_code: string;
    bank: Bank;
}

export interface NetWorthEvolutionData {
    data: Array<Record<string, string | number>>;
    accounts: Record<string, NetWorthEvolutionAccount>;
}

export interface AccountWithMetrics extends Account {
    currentBalance: number;
    previousBalance: number;
    diff: number;
    history: Array<{ date: string; value: number }>;
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

function deriveAccountMetrics(
    netWorthEvolution: NetWorthEvolutionData,
): AccountWithMetrics[] {
    const { data, accounts } = netWorthEvolution;

    if (data.length === 0 || Object.keys(accounts).length === 0) {
        return [];
    }

    return Object.values(accounts).map((account) => {
        const history = data.map((point) => ({
            date: formatMonth(point.month as string),
            value: (point[account.id] as number) ?? 0,
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
            currentBalance,
            previousBalance,
            diff: currentBalance - previousBalance,
            history,
        } as AccountWithMetrics;
    });
}

function formatMonth(yearMonth: string): string {
    const [year, month] = yearMonth.split('-');
    const date = new Date(parseInt(year), parseInt(month) - 1);

    const isCurrentYear = date.getFullYear() === new Date().getFullYear();

    return date.toLocaleDateString(
        'en-US',
        isCurrentYear
            ? { month: 'short' }
            : { year: '2-digit', month: 'short' },
    );
}

export function useDashboardData(): DashboardData & { refetch: () => void } {
    const [data, setData] = useState<Omit<DashboardData, 'isLoading'>>({
        netWorthEvolution: { data: [], accounts: {} },
        accounts: [],
        topCategories: [],
    });
    const [isLoading, setIsLoading] = useState(true);

    const fetchData = async () => {
        setIsLoading(true);
        try {
            const now = new Date();
            const to = format(now, 'yyyy-MM-dd');

            // Fetch 24 months to support YoY and rolling 12M calculations
            const from24Months = format(subMonths(now, 24), 'yyyy-MM-dd');
            const params24Months = new URLSearchParams({
                from: from24Months,
                to,
            });
            const query24Months = `?${params24Months.toString()}`;

            const from30Days = format(subDays(now, 30), 'yyyy-MM-dd');
            const params30Days = new URLSearchParams({
                from: from30Days,
                to,
            });
            const query30Days = `?${params30Days.toString()}`;

            const [netWorthEvolution, topCategories] = await Promise.all([
                fetch(
                    `/api/dashboard/net-worth-evolution${query24Months}`,
                ).then((r) => r.json()),
                fetch(`/api/dashboard/top-categories${query30Days}`).then((r) =>
                    r.json(),
                ),
            ]);

            const netWorthData = netWorthEvolution as NetWorthEvolutionData;

            setData({
                netWorthEvolution: netWorthData,
                accounts: deriveAccountMetrics(netWorthData),
                topCategories,
            });
        } catch (error) {
            console.error('Failed to fetch dashboard data:', error);
        } finally {
            setIsLoading(false);
        }
    };

    useEffect(() => {
        fetchData();
    }, []);

    return { ...data, isLoading, refetch: fetchData };
}
