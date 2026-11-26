import { index as accountsList } from '@/actions/App/Http/Controllers/AccountController';
import { index as forecastIndex } from '@/actions/App/Http/Controllers/ForecastController';
import { ForecastAccountTabs } from '@/components/forecast/forecast-account-tabs';
import { ForecastAlerts } from '@/components/forecast/forecast-alerts';
import { ForecastChart } from '@/components/forecast/forecast-chart';
import { ForecastHorizonPicker } from '@/components/forecast/forecast-horizon-picker';
import { ForecastKpis } from '@/components/forecast/forecast-kpis';
import { ForecastPlanTable } from '@/components/forecast/forecast-plan-table';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { initiallyExpandedGroups } from '@/lib/forecast/plan-rows';
import { type BreadcrumbItem } from '@/types';
import { type ForecastData, type ForecastPageProps } from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { Head, Link } from '@inertiajs/react';
import { useRef, useState } from 'react';

/** The month picked by default: the one with the problem, else the next one. */
function defaultMonth(forecast: ForecastData): string | null {
    if (forecast.alert.level === 'strong' && forecast.alert.month) {
        return forecast.alert.month;
    }

    return (
        forecast.months.find((column) => column.status === 'future')?.month ??
        forecast.months[0]?.month ??
        null
    );
}

function ForecastContent({ forecast }: { forecast: ForecastData }) {
    const [selectedMonth, setSelectedMonth] = useState(() =>
        defaultMonth(forecast),
    );
    const planRef = useRef<HTMLDivElement>(null);

    return (
        <>
            <ForecastAccountTabs
                accounts={forecast.accounts}
                selectedId={forecast.account.id}
                horizon={forecast.horizon}
            />
            <ForecastAlerts
                forecast={forecast}
                onShowMonth={(month) => {
                    setSelectedMonth(month);
                    planRef.current?.scrollIntoView({ behavior: 'smooth' });
                }}
            />
            <ForecastKpis forecast={forecast} />
            <ForecastChart
                forecast={forecast}
                selectedMonth={selectedMonth}
                onSelectMonth={setSelectedMonth}
            />
            <div ref={planRef}>
                <ForecastPlanTable
                    key={forecast.account.id}
                    forecast={forecast}
                    selectedMonth={selectedMonth}
                    initiallyExpanded={initiallyExpandedGroups(
                        forecast.plan,
                        forecast.alert.month,
                    )}
                />
            </div>
            <p className="text-xs text-muted-foreground">
                {__(
                    'How it is worked out: today’s balance + what is concrete (confirmed recurring, yearly and one-off lines) + an estimated rest per category (the median of 3 months without the concrete lines, or your budget when it fits the category). The likely range applies to the estimate only: for the most active users, a real month lands within ±40% of the estimate.',
                )}
            </p>
        </>
    );
}

/**
 * The cash-flow forecast of one account: how the user is going to be, from
 * what they know and what they usually spend.
 */
export default function ForecastPage({ forecast }: ForecastPageProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: __('Forecast'), href: forecastIndex().url },
    ];

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Forecast')} />
            <div className="mx-auto flex w-full max-w-page flex-col gap-5 p-4">
                <PageHeader
                    title={__('Forecast')}
                    description={__(
                        'How you are going to be, from what you know and what you usually spend. The more you make concrete, the more reliable it is.',
                    )}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <ForecastHorizonPicker
                                horizon={forecast.horizon}
                                accountId={forecast.account?.id ?? null}
                            />
                            {/* Adding lines lands with editing (T7). */}
                            <Button disabled title={__('Coming soon')}>
                                {__('Add to the forecast')}
                            </Button>
                        </div>
                    }
                />
                {forecast.account === null ? (
                    <Card>
                        <CardContent>
                            <EmptyState>
                                <EmptyStateTitle>
                                    {__('No accounts to forecast')}
                                </EmptyStateTitle>
                                <EmptyStateDescription>
                                    {__(
                                        'The forecast covers the accounts of this space that are not archived. Add one to see how its balance is going to evolve.',
                                    )}
                                </EmptyStateDescription>
                                <Button asChild variant="outline">
                                    <Link href={accountsList()}>
                                        {__('Go to accounts')}
                                    </Link>
                                </Button>
                            </EmptyState>
                        </CardContent>
                    </Card>
                ) : (
                    <ForecastContent
                        key={`${forecast.account.id}:${forecast.horizon}`}
                        forecast={forecast}
                    />
                )}
            </div>
        </AppSidebarLayout>
    );
}
