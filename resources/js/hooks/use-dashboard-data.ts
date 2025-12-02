import { Account, AccountType } from '@/types/account';
import { Category } from '@/types/category';
import { format, subDays, subMonths } from 'date-fns';
import { useEffect, useState } from 'react';

export interface NetWorthEvolutionAccount {
    id: string;
    name: string;
    name_iv: string;
    type: AccountType;
}

export interface NetWorthEvolutionData {
    data: Array<Record<string, string | number>>;
    accounts: Record<string, NetWorthEvolutionAccount>;
}

export interface DashboardData {
    netWorth: {
        current: number;
        previous: number;
        diff: number;
    };
    monthlySpending: {
        current: number;
        limit: null;
        previous: number;
    };
    cashFlow: {
        income: number;
        expense: number;
        previous: {
            income: number;
            expense: number;
        };
    };
    netWorthEvolution: NetWorthEvolutionData;
    accounts: Array<
        Account & {
            current_balance: number;
            previous_balance: number;
            history: Array<{ date: string; value: number }>;
        }
    >;
    topCategories: Array<{
        category: Category;
        amount: number;
    }>;
    isLoading: boolean;
}

export function useDashboardData(): DashboardData {
    const [data, setData] = useState<Omit<DashboardData, 'isLoading'>>({
        netWorth: { current: 0, previous: 0, diff: 0 },
        monthlySpending: { current: 0, limit: null, previous: 0 },
        cashFlow: {
            income: 0,
            expense: 0,
            previous: { income: 0, expense: 0 },
        },
        netWorthEvolution: { data: [], accounts: {} },
        accounts: [],
        topCategories: [],
    });
    const [isLoading, setIsLoading] = useState(true);

    useEffect(() => {
        const fetchData = async () => {
            setIsLoading(true);
            try {
                const now = new Date();
                const to = format(now, 'yyyy-MM-dd');

                // Last 12 months for evolution charts
                const from12Months = format(subMonths(now, 12), 'yyyy-MM-dd');
                const params12Months = new URLSearchParams({
                    from: from12Months,
                    to,
                });
                const query12Months = `?${params12Months.toString()}`;

                // Last 30 days for top blocks and categories
                const from30Days = format(subDays(now, 30), 'yyyy-MM-dd');
                const params30Days = new URLSearchParams({
                    from: from30Days,
                    to,
                });
                const query30Days = `?${params30Days.toString()}`;

                const [
                    netWorth,
                    monthlySpending,
                    cashFlow,
                    netWorthEvolution,
                    accountBalances,
                    topCategories,
                ] = await Promise.all([
                    fetch(`/api/dashboard/net-worth${query30Days}`).then((r) =>
                        r.json(),
                    ),
                    fetch(`/api/dashboard/monthly-spending${query30Days}`).then(
                        (r) => r.json(),
                    ),
                    fetch(`/api/dashboard/cash-flow${query30Days}`).then((r) =>
                        r.json(),
                    ),
                    fetch(
                        `/api/dashboard/net-worth-evolution${query12Months}`,
                    ).then((r) => r.json()),
                    fetch(
                        `/api/dashboard/account-balances${query12Months}`,
                    ).then((r) => r.json()),
                    fetch(`/api/dashboard/top-categories${query30Days}`).then(
                        (r) => r.json(),
                    ),
                ]);

                setData({
                    netWorth: {
                        current: netWorth.current,
                        previous: netWorth.previous,
                        diff: netWorth.current - netWorth.previous,
                    },
                    monthlySpending: {
                        current: monthlySpending.current,
                        previous: monthlySpending.previous,
                        limit: null,
                    },
                    cashFlow: {
                        income: cashFlow.current.income,
                        expense: cashFlow.current.expense,
                        previous: cashFlow.previous,
                    },
                    netWorthEvolution:
                        netWorthEvolution as NetWorthEvolutionData,
                    accounts: accountBalances.map(
                        (acc: {
                            id: string;
                            name: string;
                            current_balance: number;
                            previous_balance: number;
                        }) => ({
                            ...acc,
                            currentBalance: acc.current_balance,
                            previousBalance: acc.previous_balance,
                            diff: acc.current_balance - acc.previous_balance,
                        }),
                    ),
                    topCategories,
                });
            } catch (error) {
                console.error('Failed to fetch dashboard data:', error);
            } finally {
                setIsLoading(false);
            }
        };

        fetchData();
    }, []);

    return { ...data, isLoading };
}
