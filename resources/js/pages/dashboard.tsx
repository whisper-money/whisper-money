import { AccountBalanceCard } from '@/components/dashboard/account-balance-card';
import { CashflowSummaryCard } from '@/components/dashboard/cashflow-summary-card';
import { NetWorthChart as NetWorthChartComponent } from '@/components/dashboard/net-worth-chart';
import { TopCategoriesCard } from '@/components/dashboard/top-categories-card';
import HeadingSmall from '@/components/heading-small';
import UnlockMessageDialog from '@/components/unlock-message-dialog';
import { useEncryptionKey } from '@/contexts/encryption-key-context';
import { useDashboardData } from '@/hooks/use-dashboard-data';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { dashboard } from '@/routes';
import { BreadcrumbItem, SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

interface DashboardProps extends SharedData {
    showEncryptionPrompt: boolean;
}

export default function Dashboard() {
    const { props } = usePage<DashboardProps>();
    const {
        netWorthEvolution,
        accounts: accountMetrics,
        topCategories,
        isLoading,
        refetch,
    } = useDashboardData();
    const { isKeySet, encryptedMessageData, fetchEncryptedMessage } =
        useEncryptionKey();
    const [showUnlockDialog, setShowUnlockDialog] = useState(false);

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

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />

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
                <HeadingSmall
                    title="Dashboard"
                    description="Overview of your financial health"
                />

                <NetWorthChartComponent
                    data={netWorthEvolution}
                    loading={isLoading}
                />

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
                                  onBalanceUpdated={refetch}
                              />
                          ))}
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <TopCategoriesCard
                        categories={topCategories}
                        loading={isLoading}
                    />
                    {props.features.cashflow && (
                        <CashflowSummaryCard loading={isLoading} />
                    )}
                </div>
            </div>
        </AppSidebarLayout>
    );
}
