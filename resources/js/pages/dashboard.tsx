import { AccountBalanceCard } from '@/components/dashboard/account-balance-card';
import { NetWorthChart as NetWorthChartComponent } from '@/components/dashboard/net-worth-chart';
import { TopCategoriesCard } from '@/components/dashboard/top-categories-card';
import HeadingSmall from '@/components/heading-small';
import { useDashboardData } from '@/hooks/use-dashboard-data';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { dashboard } from '@/routes';
import { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

export default function Dashboard() {
    const {
        netWorthEvolution,
        accounts: accountMetrics,
        topCategories,
        isLoading,
    } = useDashboardData();

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />

            <div className="space-y-6 p-6">
                <HeadingSmall
                    title="Dashboard"
                    description="Overview of your financial health"
                />

                <div className="grid gap-4 md:grid-cols-1 lg:grid-cols-3">
                    <NetWorthChartComponent
                        data={netWorthEvolution}
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
