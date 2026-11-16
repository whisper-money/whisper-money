import { CustomDashboardsView } from '@/components/dashboard/custom-dashboards/custom-dashboards-view';
import { DashboardLayoutView } from '@/components/dashboard/widgets/dashboard-layout-view';
import { SYSTEM_DASHBOARD_LAYOUT } from '@/components/dashboard/widgets/registry';
import HeadingSmall from '@/components/heading-small';
import { IntegrationRequestsDrawer } from '@/components/integration-requests/integration-requests-drawer';
import UnlockMessageDialog from '@/components/unlock-message-dialog';
import { useEncryptionKey } from '@/contexts/encryption-key-context';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { type DashboardProps } from '@/types/dashboard';
import { __ } from '@/utils/i18n';
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export default function Dashboard() {
    const { props } = usePage<DashboardProps>();
    const { isKeySet, encryptedMessageData, fetchEncryptedMessage } =
        useEncryptionKey();
    const [showUnlockDialog, setShowUnlockDialog] = useState(false);
    const [integrationDrawerOpen, setIntegrationDrawerOpen] = useState(
        !!props.openIntegrationRequests,
    );

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: __('Dashboard'),
            href: dashboard().url,
        },
    ];

    useEffect(() => {
        // Fetch encrypted message data if not already loaded
        if (!encryptedMessageData) {
            fetchEncryptedMessage();
        }

        // Auto-open the unlock dialog only if:
        // 1. User just logged in (showEncryptionPrompt is true)
        // 2. Encryption key is not set
        // 3. Encrypted message data is available
        if (props.showEncryptionPrompt && !isKeySet && encryptedMessageData) {
            setShowUnlockDialog(true);
        }
    }, [
        isKeySet,
        encryptedMessageData,
        fetchEncryptedMessage,
        props.showEncryptionPrompt,
    ]);

    function handleUnlock() {
        setShowUnlockDialog(false);
    }

    const heading = (
        <HeadingSmall
            title={__('Dashboard')}
            description={__('Overview of your financial health')}
        />
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

            {encryptedMessageData && (
                <UnlockMessageDialog
                    open={showUnlockDialog}
                    onOpenChange={setShowUnlockDialog}
                    onUnlock={handleUnlock}
                    encryptedContent={encryptedMessageData.encrypted_content}
                    iv={encryptedMessageData.iv}
                    salt={encryptedMessageData.salt}
                />
            )}

            <div className="space-y-6 p-6">
                {props.features.customDashboards ? (
                    <CustomDashboardsView
                        data={props}
                        heading={heading}
                        notice={null}
                    />
                ) : (
                    <>
                        {heading}
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
