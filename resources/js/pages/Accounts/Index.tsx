import { index } from '@/actions/App/Http/Controllers/AccountController';
import { AccountListCard } from '@/components/accounts/account-list-card';
import HeadingSmall from '@/components/heading-small';
import { useDashboardData } from '@/hooks/use-dashboard-data';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { BreadcrumbItem } from '@/types';
import { Account, AccountType } from '@/types/account';
import { Head } from '@inertiajs/react';
import { useMemo } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Accounts',
        href: index().url,
    },
];

const ACCOUNT_TYPE_ORDER: AccountType[] = [
    'checking',
    'savings',
    'investment',
    'retirement',
    'loan',
    'credit_card',
    'others',
];

interface Props {
    accounts: Account[];
}

export default function AccountsIndex({ accounts }: Props) {
    const { accounts: accountMetrics, isLoading, refetch } = useDashboardData();

    const accountsWithMetrics = useMemo(() => {
        return accounts.map((account) => {
            const metrics = accountMetrics.find((m) => m.id === account.id);
            return {
                ...account,
                currentBalance: metrics?.currentBalance ?? 0,
                previousBalance: metrics?.previousBalance ?? 0,
                diff: metrics?.diff ?? 0,
                history: metrics?.history ?? [],
            };
        });
    }, [accounts, accountMetrics]);

    const groupedAccounts = useMemo(() => {
        const groups: Record<AccountType, typeof accountsWithMetrics> = {
            checking: [],
            savings: [],
            investment: [],
            retirement: [],
            loan: [],
            credit_card: [],
            others: [],
        };

        accountsWithMetrics.forEach((account) => {
            const type = account.type as AccountType;
            if (groups[type]) {
                groups[type].push(account);
            } else {
                groups.others.push(account);
            }
        });

        return groups;
    }, [accountsWithMetrics]);

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title="Accounts" />

            <div className="space-y-8 p-6">
                <HeadingSmall
                    title="Accounts"
                    description="View and manage your bank accounts"
                />

                <div className="">
                    {ACCOUNT_TYPE_ORDER.map((type) => {
                        const accountsInGroup = groupedAccounts[type];
                        if (accountsInGroup.length === 0) return null;

                        return (
                            <>
                                {isLoading
                                    ? accountsInGroup.map((account) => (
                                        <AccountListCard
                                            key={account.id}
                                            account={account}
                                            loading={true}
                                        />
                                    ))
                                    : accountsInGroup.map((account) => (
                                        <AccountListCard
                                            key={account.id}
                                            account={account}
                                            onBalanceUpdated={refetch}
                                        />
                                    ))}
                            </>
                        );
                    })}
                </div>

                {accounts.length === 0 && !isLoading && (
                    <div className="flex h-[300px] items-center justify-center text-muted-foreground">
                        No accounts found. Add your first account in Settings.
                    </div>
                )}
            </div>
        </AppSidebarLayout>
    );
}
