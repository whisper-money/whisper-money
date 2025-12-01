import { AccountBalanceCard } from '@/components/dashboard/account-balance-card';
import { CashFlowCard } from '@/components/dashboard/cash-flow-card';
import { NetWorthCard } from '@/components/dashboard/net-worth-card';
import { NetWorthChart as NetWorthChartComponent } from '@/components/dashboard/net-worth-chart';
import { SpendingSummaryCard } from '@/components/dashboard/spending-summary-card';
import { TopCategoriesCard } from '@/components/dashboard/top-categories-card';
import HeadingSmall from '@/components/heading-small';
import { useDashboardData } from '@/hooks/use-dashboard-data';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { dashboard } from '@/routes';
import { BreadcrumbItem } from '@/types';
import { Account, Bank } from '@/types/account';
import { Category } from '@/types/category';
import { Head } from '@inertiajs/react';

interface Props {
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

export default function Dashboard({ categories, accounts }: Props) {
    const {
        netWorth,
        monthlySpending,
        cashFlow,
        netWorthHistory,
        accounts: accountMetrics,
        topCategories,
        isLoading,
    } = useDashboardData(categories, accounts);

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />

            <div className="space-y-6 p-6">
                <HeadingSmall
                    title="Dashboard"
                    description="Overview of your financial health"
                />

                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <NetWorthCard
                        current={netWorth.current}
                        previous={netWorth.previous}
                        loading={isLoading}
                    />
                    <SpendingSummaryCard
                        current={monthlySpending.current}
                        previous={monthlySpending.previous}
                        loading={isLoading}
                    />
                    <CashFlowCard
                        income={cashFlow.income}
                        expense={cashFlow.expense}
                        previous={cashFlow.previous}
                        loading={isLoading}
                    />
                </div>

                <div className="grid gap-4 md:grid-cols-1 lg:grid-cols-3">
                    <NetWorthChartComponent
                        data={netWorthHistory}
                        loading={isLoading}
                    />
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    {isLoading
                        ? Array.from({ length: 4 }).map((_, i) => (
                              <AccountBalanceCard
                                  key={i}
                                  // @ts-expect-error - mock data for loading state
                                  account={{}}
                                  loading={true}
                              />
                          ))
                        : accountMetrics.map((account) => (
                              <AccountBalanceCard
                                  key={account.id}
                                  account={account}
                              />
                          ))}
                </div>

                <div className="grid gap-4 md:grid-cols-1 lg:grid-cols-3">
                    <TopCategoriesCard
                        categories={topCategories}
                        loading={isLoading}
                    />
                </div>
            </div>
        </AppSidebarLayout>
    );
}
