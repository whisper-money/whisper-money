import { AccountBalanceCard } from '@/components/dashboard/account-balance-card';
import { CashflowSummaryCard } from '@/components/dashboard/cashflow-summary-card';
import { NetWorthChart as NetWorthChartComponent } from '@/components/dashboard/net-worth-chart';
import { TopCategoriesCard } from '@/components/dashboard/top-categories-card';
import HeadingSmall from '@/components/heading-small';
import UnlockMessageDialog from '@/components/unlock-message-dialog';
import { useEncryptionKey } from '@/contexts/encryption-key-context';
import {
    type NetWorthEvolutionData,
    deriveAccountMetrics,
} from '@/hooks/use-dashboard-data';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { dashboard } from '@/routes';
import { BreadcrumbItem, SharedData } from '@/types';
import { Category } from '@/types/category';
import { __ } from '@/utils/i18n';
import { Deferred, Head, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';

interface CashflowSummary {
    income: number;
    expense: number;
    net: number;
    savings_rate: number;
}

interface DashboardProps extends SharedData {
    showEncryptionPrompt: boolean;
    netWorthEvolution?: NetWorthEvolutionData;
    topCategories?: Array<{
        category: Category;
        amount: number;
        previous_amount: number;
        total_amount: number;
    }>;
    cashflowSummary?: {
        current: CashflowSummary;
        previous: CashflowSummary;
    };
}

export default function Dashboard() {
    const { props } = usePage<DashboardProps>();
    const { isKeySet, encryptedMessageData, fetchEncryptedMessage } =
        useEncryptionKey();
    const [showUnlockDialog, setShowUnlockDialog] = useState(false);

    const netWorthEvolution = props.netWorthEvolution ?? {
        data: [],
        accounts: {},
        currency_code: 'USD',
    };

    const accountMetrics = useMemo(
        () => deriveAccountMetrics(netWorthEvolution),
        [netWorthEvolution],
    );

    const topCategories = props.topCategories ?? [];

    const refetch = useCallback(() => {
        router.reload({
            only: ['netWorthEvolution', 'topCategories', 'cashflowSummary'],
        });
    }, []);

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

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Dashboard')} />

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
                    title={__('Dashboard')}
                    description={__('Overview of your financial health')}
                />

                <Deferred
                    data="netWorthEvolution"
                    fallback={
                        <>
                            <NetWorthChartComponent
                                data={{
                                    data: [],
                                    accounts: {},
                                    currency_code: 'USD',
                                }}
                                loading={true}
                            />
                            <div className="grid gap-4 md:grid-cols-2">
                                {Array.from({ length: 4 }).map((_, i) => (
                                    <AccountBalanceCard
                                        key={i}
                                        // @ts-expect-error - mock data for loading state
                                        account={{}}
                                        loading={true}
                                    />
                                ))}
                            </div>
                        </>
                    }
                >
                    <NetWorthChartComponent data={netWorthEvolution} />

                    <div className="grid gap-4 md:grid-cols-2">
                        {accountMetrics.map((account) => (
                            <AccountBalanceCard
                                key={account.id}
                                account={account}
                                onBalanceUpdated={refetch}
                            />
                        ))}
                    </div>
                </Deferred>

                <div className="flex flex-col gap-6">
                    <Deferred
                        data="topCategories"
                        fallback={
                            <TopCategoriesCard categories={[]} loading={true} />
                        }
                    >
                        <TopCategoriesCard categories={topCategories} />
                    </Deferred>

                    {props.features.cashflow && (
                        <Deferred
                            data="cashflowSummary"
                            fallback={<CashflowSummaryCard loading={true} />}
                        >
                            <CashflowSummaryCard
                                data={props.cashflowSummary ?? null}
                            />
                        </Deferred>
                    )}
                </div>
            </div>
        </AppSidebarLayout>
    );
}
