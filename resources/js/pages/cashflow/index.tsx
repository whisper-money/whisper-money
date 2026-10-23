import { AvailableCard } from '@/components/cashflow/available-card';
import { BreakdownCard } from '@/components/cashflow/breakdown-card';
import { type CashflowBlockHrefs } from '@/components/cashflow/cashflow-blocks';
import { CashflowDetailsDrawer } from '@/components/cashflow/cashflow-details-drawer';
import { ExpensesCard } from '@/components/cashflow/expenses-card';
import { MonthlySavingsCashflowCard } from '@/components/cashflow/monthly-savings-cashflow-card';
import { NetCashflowCard } from '@/components/cashflow/net-cashflow-card';
import {
    formatPeriodLabel,
    PeriodNavigation,
} from '@/components/cashflow/period-navigation';
import { SavedInvestedCard } from '@/components/cashflow/saved-invested-card';
import { CashflowTrendChart, SankeyChart } from '@/components/charts';
import HeadingSmall from '@/components/heading-small';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CashflowPeriodType, useCashflowData } from '@/hooks/use-cashflow-data';
import { useLocale } from '@/hooks/use-locale';
import { usePeriodUrlSync } from '@/hooks/use-period-url-sync';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import {
    CASHFLOW_BLOCKS,
    cashflowBlockTransactionsUrl,
} from '@/lib/cashflow-block';
import { cn } from '@/lib/utils';
import { cashflow } from '@/routes';
import { BreadcrumbItem, Features } from '@/types';
import { __ } from '@/utils/i18n';
import { Head, usePage } from '@inertiajs/react';
import {
    endOfMonth,
    endOfQuarter,
    endOfYear,
    parse,
    startOfMonth,
    startOfQuarter,
    startOfYear,
} from 'date-fns';
import { TriangleAlertIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

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

/**
 * Each block's transactions over the range the summary covers, so the list a
 * link opens adds up to the figure next to it.
 */
function blockTransactionsHrefs(period: {
    from: Date;
    to: Date;
}): CashflowBlockHrefs {
    return Object.fromEntries(
        CASHFLOW_BLOCKS.map((block) => [
            block,
            cashflowBlockTransactionsUrl(block, period.from, period.to),
        ]),
    );
}

export default function CashflowPage() {
    const {
        auth,
        period: initialPeriod,
        periodType: initialPeriodType,
        features,
    } = usePage<{
        auth: { user: { currency_code: string } };
        features: Features;
        period: string | null;
        periodType: CashflowPeriodType;
    }>().props;

    const [periodType, setPeriodType] =
        useState<CashflowPeriodType>(initialPeriodType);

    const [currentDate, setCurrentDate] = useState<Date>(() =>
        parsePeriodParam(initialPeriod, initialPeriodType),
    );

    // Memoized so children (e.g. the Sankey drill-down fetch) don't see a new
    // object identity on every render.
    const period = useMemo(
        () => getPeriodRange(currentDate, periodType),
        [currentDate, periodType],
    );

    const {
        summary,
        sankey,
        trend,
        incomeBreakdown,
        expenseBreakdown,
        isLoading,
        hasError,
        refetch,
    } = useCashflowData({
        ...period,
        periodType,
    });

    // The figures in state after a failed load are either zeros or the period the
    // user navigated away from, so every card below stays in its placeholder while
    // the banner explains why. Showing them would be answering a question about
    // someone's money with a number we do not have.
    const showPlaceholders = isLoading || hasError;
    const showAvailable = features.cashflowAvailableFree;
    const [detailsOpen, setDetailsOpen] = useState(false);
    const locale = useLocale();
    const blockHrefs = useMemo(() => blockTransactionsHrefs(period), [period]);

    usePeriodUrlSync(currentDate, periodType, initialPeriod, initialPeriodType);

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

                {hasError && (
                    <Alert variant="destructive">
                        <TriangleAlertIcon />
                        <AlertDescription className="flex flex-wrap items-center gap-3">
                            <span>
                                {__(
                                    'We could not load these figures. Try again in a moment.',
                                )}
                            </span>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={refetch}
                            >
                                {__('Try again')}
                            </Button>
                        </AlertDescription>
                    </Alert>
                )}

                {/* Summary Cards */}
                <div
                    className={cn(
                        'grid gap-6 md:grid-cols-2',
                        showAvailable && 'xl:grid-cols-4',
                    )}
                >
                    <NetCashflowCard
                        current={summary.current}
                        previous={summary.previous}
                        loading={showPlaceholders}
                        currency={auth.user.currency_code}
                    />

                    <SavedInvestedCard
                        current={summary.current}
                        previous={summary.previous}
                        loading={showPlaceholders}
                        currency={auth.user.currency_code}
                        showContributionSplit={showAvailable}
                    />

                    {showAvailable && (
                        <>
                            <AvailableCard
                                current={summary.current}
                                previous={summary.previous}
                                loading={showPlaceholders}
                                currency={auth.user.currency_code}
                            />
                            <ExpensesCard
                                current={summary.current}
                                loading={showPlaceholders}
                                currency={auth.user.currency_code}
                                hrefs={blockHrefs}
                                onViewDetails={() => setDetailsOpen(true)}
                            />
                        </>
                    )}
                </div>

                {showAvailable && (
                    <CashflowDetailsDrawer
                        // Closed while the figures load or fail: it would
                        // otherwise answer with the zeros in their place.
                        open={detailsOpen && !showPlaceholders}
                        onOpenChange={setDetailsOpen}
                        current={summary.current}
                        previous={summary.previous}
                        currency={auth.user.currency_code}
                        periodType={periodType}
                        hrefs={blockHrefs}
                        periodLabel={formatPeriodLabel(
                            currentDate,
                            periodType,
                            locale,
                        )}
                    />
                )}

                {/* Only a month lines up with a monthly goal's target. */}
                {periodType === 'month' && (
                    <MonthlySavingsCashflowCard
                        month={currentDate}
                        net={showPlaceholders ? null : summary.current.net}
                        currencyCode={auth.user.currency_code}
                    />
                )}

                {/* Trend Chart */}
                <CashflowTrendChart
                    data={trend}
                    loading={showPlaceholders}
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
                        {showPlaceholders ? (
                            <div className="h-[400px] animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                        ) : (
                            <SankeyChart
                                data={sankey}
                                height={400}
                                currency={auth.user.currency_code}
                                period={period}
                                centerLabel={
                                    showAvailable ? __('Available') : undefined
                                }
                            />
                        )}
                    </CardContent>
                </Card>

                {/* Breakdown Cards */}
                <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <BreakdownCard
                        type="income"
                        data={incomeBreakdown}
                        loading={showPlaceholders}
                        currency={auth.user.currency_code}
                        period={period}
                    />

                    <BreakdownCard
                        type="expense"
                        data={expenseBreakdown}
                        loading={showPlaceholders}
                        currency={auth.user.currency_code}
                        period={period}
                    />
                </div>
            </div>
        </AppSidebarLayout>
    );
}
