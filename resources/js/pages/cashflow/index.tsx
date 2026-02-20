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
import { __ } from '@/utils/i18n';
import { Head, router, usePage } from '@inertiajs/react';
import { endOfMonth, format, parse, startOfMonth } from 'date-fns';
import { useEffect, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Cashflow',
        href: cashflow().url,
    },
];

export default function CashflowPage() {
    const { auth } = usePage<{ auth: { user: { currency_code: string } } }>()
        .props;

    // Initialize currentDate from URL query param or default to current month
    const [currentDate, setCurrentDate] = useState<Date>(() => {
        const urlParams = new URLSearchParams(window.location.search);
        const periodParam = urlParams.get('period');

        if (periodParam) {
            try {
                // Parse YYYY-MM format
                const parsedDate = parse(periodParam, 'yyyy-MM', new Date());
                // Validate it's a valid date
                if (!isNaN(parsedDate.getTime())) {
                    return parsedDate;
                }
            } catch {
                // Fall through to default
            }
        }

        return new Date();
    });

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

    // Update URL when currentDate changes
    useEffect(() => {
        const periodParam = format(currentDate, 'yyyy-MM');
        const currentPeriodParam = new URLSearchParams(
            window.location.search,
        ).get('period');

        // Only update if the period has changed
        if (currentPeriodParam !== periodParam) {
            router.visit(cashflow({ query: { period: periodParam } }).url, {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            });
        }
    }, [currentDate]);

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Cashflow')} />

            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <HeadingSmall
                        title={__('Cashflow')}
                        description={__(
                            'Track your income, expenses, and savings',
                        )}
                    />

                    <PeriodNavigation
                        currentDate={currentDate}
                        onDateChange={setCurrentDate}
                    />
                </div>

                {/* Summary Cards */}
                <div className="grid gap-6 md:grid-cols-2">
                    <NetCashflowCard
                        current={summary.current}
                        previous={summary.previous}
                        loading={isLoading}
                        currency={auth.user.currency_code}
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
                        <CardTitle className="text-base">
                            {__('Money Flow')}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {isLoading ? (
                            <div className="h-[400px] animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                        ) : (
                            <SankeyChart
                                data={sankey}
                                height={400}
                                currency={auth.user.currency_code}
                            />
                        )}
                    </CardContent>
                </Card>

                {/* Breakdown Cards */}
                <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <BreakdownCard
                        type="income"
                        data={incomeBreakdown}
                        loading={isLoading}
                        currency={auth.user.currency_code}
                    />

                    <BreakdownCard
                        type="expense"
                        data={expenseBreakdown}
                        loading={isLoading}
                        currency={auth.user.currency_code}
                    />
                </div>

                {/* Trend Chart */}
                <CashflowTrendChart
                    data={trend}
                    loading={isLoading}
                    currency={auth.user.currency_code}
                />
            </div>
        </AppSidebarLayout>
    );
}
