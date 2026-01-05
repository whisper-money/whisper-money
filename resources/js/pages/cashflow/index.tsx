import { BreakdownCard } from '@/components/cashflow/breakdown-card';
import { NetCashflowCard } from '@/components/cashflow/net-cashflow-card';
import { PeriodNavigation } from '@/components/cashflow/period-navigation';
import { SavingsRateCard } from '@/components/cashflow/savings-rate-card';
import { CashflowTrendChart, SankeyChart } from '@/components/charts';
import HeadingSmall from '@/components/heading-small';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useCashflowData } from '@/hooks/use-cashflow-data';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { cashflow } from '@/routes';
import { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { endOfMonth, startOfMonth } from 'date-fns';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Cashflow',
        href: cashflow().url,
    },
];

export default function CashflowPage() {
    const [currentDate, setCurrentDate] = useState(new Date());

    const period = {
        from: startOfMonth(currentDate),
        to: endOfMonth(currentDate),
    };

    const {
        summary,
        sankey,
        trend,
        incomeBreakdown,
        expenseBreakdown,
        isLoading,
    } = useCashflowData(period);

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title="Cashflow" />

            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <HeadingSmall
                        title="Cashflow"
                        description="Track your income, expenses, and savings"
                    />
                    <PeriodNavigation
                        currentDate={currentDate}
                        onDateChange={setCurrentDate}
                    />
                </div>

                {/* Summary Cards */}
                <div className="grid gap-4 md:grid-cols-2">
                    <NetCashflowCard
                        current={summary.current}
                        previous={summary.previous}
                        loading={isLoading}
                    />
                    <SavingsRateCard
                        current={summary.current}
                        previous={summary.previous}
                        loading={isLoading}
                    />
                </div>

                {/* Sankey Diagram */}
                <Card>
                    <CardHeader className="pb-4">
                        <CardTitle className="text-base">Money Flow</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {isLoading ? (
                            <div className="h-[400px] animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                        ) : (
                            <SankeyChart data={sankey} height={400} />
                        )}
                    </CardContent>
                </Card>

                {/* Breakdown Cards */}
                <div className="grid gap-4 md:grid-cols-2">
                    <BreakdownCard
                        type="income"
                        data={incomeBreakdown}
                        loading={isLoading}
                    />
                    <BreakdownCard
                        type="expense"
                        data={expenseBreakdown}
                        loading={isLoading}
                    />
                </div>

                {/* Trend Chart */}
                <CashflowTrendChart data={trend} loading={isLoading} />
            </div>
        </AppSidebarLayout>
    );
}
