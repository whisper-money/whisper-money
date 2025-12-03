import { Account, AccountType } from '@/types/account';
import { Category } from '@/types/category';
import { format, subDays, subMonths } from 'date-fns';
import { useEffect, useState } from 'react';

export interface NetWorthEvolutionAccount {
    id: string;
    name: string;
    name_iv: string;
    type: AccountType;
    currency_code: string;
}

export interface NetWorthEvolutionData {
    data: Array<Record<string, string | number>>;
    accounts: Record<string, NetWorthEvolutionAccount>;
}

export interface DashboardData {
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
        previous_amount: number;
        total_amount: number;
    }>;
    isLoading: boolean;
}

export function useDashboardData(): DashboardData {
    const [data, setData] = useState<Omit<DashboardData, 'isLoading'>>({
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

                const [netWorthEvolution, accountBalances, topCategories] =
                    await Promise.all([
                        fetch(
                            `/api/dashboard/net-worth-evolution${query12Months}`,
                        ).then((r) => r.json()),
                        fetch(
                            `/api/dashboard/account-balances${query12Months}`,
                        ).then((r) => r.json()),
                        fetch(
                            `/api/dashboard/top-categories${query30Days}`,
                        ).then((r) => r.json()),
                    ]);

                setData({
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
