import { BreakdownCard } from '@/components/cashflow/breakdown-card';
import { NetCashflowCard } from '@/components/cashflow/net-cashflow-card';
import { PeriodNavigation } from '@/components/cashflow/period-navigation';
import { SavingsRateCard } from '@/components/cashflow/savings-rate-card';
import { CashflowTrendChart, SankeyChart } from '@/components/charts';
import HeadingSmall from '@/components/heading-small';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CashflowPeriodType, useCashflowData } from '@/hooks/use-cashflow-data';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { cashflow } from '@/routes';
import { BreadcrumbItem } from '@/types';
import { __ } from '@/utils/i18n';
import { Head, router, usePage } from '@inertiajs/react';
import {
    endOfMonth,
    endOfQuarter,
    endOfYear,
    format,
    getQuarter,
    parse,
    startOfMonth,
    startOfQuarter,
    startOfYear,
} from 'date-fns';
import { useEffect, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Cashflow',
        href: cashflow().url,
    },
];

function parsePeriodParam(
    period: string | null,
    periodType: CashflowPeriodType,
): Date {
    if (!period) {
        return new Date();
    }

    try {
        if (periodType === 'quarter') {
            const match = /^(\d{4})-Q([1-4])$/.exec(period);

            if (match) {
                return new Date(Number(match[1]), (Number(match[2]) - 1) * 3);
            }
        }

        if (periodType === 'year') {
            const match = /^(\d{4})$/.exec(period);

            if (match) {
                return new Date(Number(match[1]), 0);
            }
        }

        const parsedDate = parse(period, 'yyyy-MM', new Date());

        if (!isNaN(parsedDate.getTime())) {
            return parsedDate;
        }
    } catch {
        return new Date();
    }

    return new Date();
}

function getPeriodRange(
    currentDate: Date,
    periodType: CashflowPeriodType,
): { from: Date; to: Date } {
    if (periodType === 'quarter') {
        return {
            from: startOfQuarter(currentDate),
            to: endOfQuarter(currentDate),
        };
    }

    if (periodType === 'year') {
        return {
            from: startOfYear(currentDate),
            to: endOfYear(currentDate),
        };
    }

    return {
        from: startOfMonth(currentDate),
        to: endOfMonth(currentDate),
    };
}

function formatPeriodParam(
    currentDate: Date,
    periodType: CashflowPeriodType,
): string {
    if (periodType === 'quarter') {
        return `${format(currentDate, 'yyyy')}-Q${getQuarter(currentDate)}`;
    }

    if (periodType === 'year') {
        return format(currentDate, 'yyyy');
    }

    return format(currentDate, 'yyyy-MM');
}

export default function CashflowPage() {
    const {
        auth,
        period: initialPeriod,
        periodType: initialPeriodType,
    } = usePage<{
        auth: { user: { currency_code: string } };
        period: string | null;
        periodType: CashflowPeriodType;
    }>().props;

    const [periodType, setPeriodType] =
        useState<CashflowPeriodType>(initialPeriodType);

    const [currentDate, setCurrentDate] = useState<Date>(() =>
        parsePeriodParam(initialPeriod, initialPeriodType),
    );

    const period = getPeriodRange(currentDate, periodType);

    const {
        summary,
        sankey,
        trend,
        incomeBreakdown,
        expenseBreakdown,
        isLoading,
    } = useCashflowData({ ...period, periodType });

    useEffect(() => {
        const periodParam = formatPeriodParam(currentDate, periodType);

        if (initialPeriod !== periodParam || initialPeriodType !== periodType) {
            router.visit(
                cashflow({
                    query: { period: periodParam, period_type: periodType },
                }).url,
                {
                    preserveScroll: true,
                    preserveState: true,
                    replace: true,
                },
            );
        }
    }, [currentDate, initialPeriod, initialPeriodType, periodType]);

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Cashflow')} />

            <div className="space-y-6 p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <HeadingSmall
                        title={__('Cashflow')}
                        description={__(
                            'Track your income, expenses, and savings',
                        )}
                    />

                    <PeriodNavigation
                        currentDate={currentDate}
                        periodType={periodType}
                        onDateChange={setCurrentDate}
                        onPeriodTypeChange={setPeriodType}
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
                        currency={auth.user.currency_code}
                    />
                </div>

                {/* Trend Chart */}
                <CashflowTrendChart
                    data={trend}
                    loading={isLoading}
                    currency={auth.user.currency_code}
                    periodType={periodType}
                />

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
                                period={period}
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
                        period={period}
                    />

                    <BreakdownCard
                        type="expense"
                        data={expenseBreakdown}
                        loading={isLoading}
                        currency={auth.user.currency_code}
                        period={period}
                    />
                </div>
            </div>
        </AppSidebarLayout>
    );
}
