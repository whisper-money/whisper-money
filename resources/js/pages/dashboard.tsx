import { CustomDashboardsView } from '@/components/dashboard/custom-dashboards/custom-dashboards-view';
import MonthlySummaryNotice from '@/components/dashboard/monthly-summary-notice';
import { DashboardLayoutView } from '@/components/dashboard/widgets/dashboard-layout-view';
import { SYSTEM_DASHBOARD_LAYOUT } from '@/components/dashboard/widgets/registry';
import HeadingSmall from '@/components/heading-small';
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

    const heading = (
        <HeadingSmall
            title={__('Dashboard')}
            description={__('Overview of your financial health')}
        />
    );
    const notice = props.monthlySummary && (
        <MonthlySummaryNotice summary={props.monthlySummary} />
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
                {props.features.customDashboards ? (
                    <CustomDashboardsView
                        data={props}
                        heading={heading}
                        notice={notice}
                    />
                ) : (
                    <>
                        {heading}
                        {notice}
                        <DashboardLayoutView
                            layout={SYSTEM_DASHBOARD_LAYOUT}
                            data={props}
                        />
                    </>
                )}
            </div>
        </AppSidebarLayout>
    );
}
