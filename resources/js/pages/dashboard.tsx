import { CustomDashboardsView } from '@/components/dashboard/custom-dashboards/custom-dashboards-view';
import { ForecastAlertNotice } from '@/components/dashboard/forecast-alert-notice';
import MonthlySummaryNotice from '@/components/dashboard/monthly-summary-notice';
import { IntegrationRequestsDrawer } from '@/components/integration-requests/integration-requests-drawer';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { type DashboardProps } from '@/types/dashboard';
import { __ } from '@/utils/i18n';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function Dashboard() {
    const { props } = usePage<DashboardProps>();
    const [integrationDrawerOpen, setIntegrationDrawerOpen] = useState(
        !!props.openIntegrationRequests,
    );

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: __('Dashboard'),
            href: dashboard().url,
        },
    ];

    const notice = (props.forecastAlert || props.monthlySummary) && (
        <>
            {props.forecastAlert && (
                <ForecastAlertNotice alert={props.forecastAlert} />
            )}
            {props.monthlySummary && (
                <MonthlySummaryNotice summary={props.monthlySummary} />
            )}
        </>
    );

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Dashboard')} />

            <IntegrationRequestsDrawer
                open={integrationDrawerOpen}
                onOpenChange={(open) => {
                    setIntegrationDrawerOpen(open);
                    if (!open) {
                        router.visit(dashboard().url, { preserveScroll: true });
                    }
                }}
            />

            <div className="space-y-6 p-6">
                <CustomDashboardsView
                    data={props}
                    title={__('Dashboard')}
                    description={__('Overview of your financial health')}
                    notice={notice}
                />
            </div>
        </AppSidebarLayout>
    );
}
