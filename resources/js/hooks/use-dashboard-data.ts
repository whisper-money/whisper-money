import { useLocale } from '@/hooks/use-locale';
import { netWorthContribution } from '@/lib/chart-calculations';
import { fetchJson } from '@/lib/fetch-json';
import { Account, AccountType, Bank } from '@/types/account';
import { Category } from '@/types/category';
import { formatMonthFromYearMonth } from '@/utils/date';
import { format, subDays, subMonths } from 'date-fns';
import { useCallback, useEffect, useRef, useState } from 'react';

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
    hidden_on_dashboard?: boolean;
    archived_at?: string | null;
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
    hidden_on_dashboard: boolean;
    archived_at: string | null;
}

export interface DashboardData {
    netWorthEvolution: NetWorthEvolutionData;
    accounts: AccountWithMetrics[];
    topCategories: Array<{
        category: Category | null;
        category_id?: string | null;
        amount: number;
        previous_amount: number;
        total_amount: number;
        has_children?: boolean;
        is_direct?: boolean;
    }>;
    isLoading: boolean;
    hasError: boolean;
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
                    ? netWorthContribution(
                          account.type,
                          point[account.id] as number,
                      )
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
            hidden_on_dashboard: account.hidden_on_dashboard ?? false,
            archived_at: account.archived_at ?? null,
        } as AccountWithMetrics;
    });
}

export function useDashboardData(): DashboardData & { refetch: () => void } {
    const locale = useLocale();
    const [data, setData] = useState<Omit<DashboardData, 'isLoading' | 'hasError'>>({
        netWorthEvolution: { data: [], accounts: {}, currency_code: 'USD' },
        accounts: [],
        topCategories: [],
    });
    const [isLoading, setIsLoading] = useState(true);
    const [hasError, setHasError] = useState(false);

    // See the same guard in use-cashflow-data: whichever load is current owns the
    // state, so a slow one settling late cannot overwrite it.
    const latestRequest = useRef(0);

    const fetchData = useCallback(async () => {
        const request = ++latestRequest.current;

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
                fetchJson<NetWorthEvolutionData>(
                    `/api/dashboard/net-worth-evolution${query12Months}`,
                ),
                fetchJson<DashboardData['topCategories']>(
                    `/api/dashboard/top-categories${query30Days}`,
                ),
            ]);

            const netWorthData = netWorthEvolution as NetWorthEvolutionData;

            if (request !== latestRequest.current) {
                return;
            }

            setData({
                netWorthEvolution: netWorthData,
                accounts: deriveAccountMetrics(netWorthData, locale),
                topCategories,
            });
            setHasError(false);
        } catch (error) {
            if (request !== latestRequest.current) {
                return;
            }

            console.error('Failed to fetch dashboard data:', error);

            // A flat net worth and an empty category list read as a true answer
            // about the user's money. The page shows the failure instead.
            setHasError(true);
        } finally {
            if (request === latestRequest.current) {
                setIsLoading(false);
            }
        }
    }, [locale]);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

    return { ...data, isLoading, hasError, refetch: fetchData };
}
