import {
    reorder,
    updateVisibility,
} from '@/actions/App/Http/Controllers/AccountController';
import { AccountBalanceCard } from '@/components/dashboard/account-balance-card';
import { AccountsManagerDialog } from '@/components/dashboard/accounts-manager-dialog';
import { CashflowSummaryCard } from '@/components/dashboard/cashflow-summary-card';
import { NetWorthChart as NetWorthChartComponent } from '@/components/dashboard/net-worth-chart';
import { TopCategoriesCard } from '@/components/dashboard/top-categories-card';
import HeadingSmall from '@/components/heading-small';
import { IntegrationRequestsDrawer } from '@/components/integration-requests/integration-requests-drawer';
import { Button } from '@/components/ui/button';
import UnlockMessageDialog from '@/components/unlock-message-dialog';
import { useEncryptionKey } from '@/contexts/encryption-key-context';
import {
    type AccountWithMetrics,
    type NetWorthEvolutionData,
    deriveAccountMetrics,
} from '@/hooks/use-dashboard-data';
import { useLocale } from '@/hooks/use-locale';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { dashboard } from '@/routes';
import { BreadcrumbItem, SharedData } from '@/types';
import { Category } from '@/types/category';
import { __ } from '@/utils/i18n';
import { Deferred, Head, router, usePage } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
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
        category: Category | null;
        category_id?: string | null;
        amount: number;
        previous_amount: number;
        total_amount: number;
        has_children?: boolean;
        is_direct?: boolean;
    }>;
    cashflowSummary?: {
        current: CashflowSummary;
        previous: CashflowSummary;
    };
    openIntegrationRequests?: boolean;
}

export default function Dashboard() {
    const { props } = usePage<DashboardProps>();
    const locale = useLocale();
    const { isKeySet, encryptedMessageData, fetchEncryptedMessage } =
        useEncryptionKey();
    const [showUnlockDialog, setShowUnlockDialog] = useState(false);
    const [integrationDrawerOpen, setIntegrationDrawerOpen] = useState(
        !!props.openIntegrationRequests,
    );

    const netWorthEvolution = useMemo(
        () =>
            props.netWorthEvolution ?? {
                data: [],
                accounts: {},
                currency_code: 'USD',
            },
        [props.netWorthEvolution],
    );

    const accountMetrics = useMemo(
        () => deriveAccountMetrics(netWorthEvolution, locale),
        [netWorthEvolution, locale],
    );

    // Identify linked loan account IDs and filter them out
    const linkedLoanAccountIds = useMemo(() => {
        const ids = new Set<string>();
        accountMetrics.forEach((a) => {
            if (a.type === 'real_estate' && a.linked_loan_account_id) {
                ids.add(a.linked_loan_account_id);
            }
        });
        return ids;
    }, [accountMetrics]);

    const manageableAccounts = useMemo(
        () => accountMetrics.filter((a) => !linkedLoanAccountIds.has(a.id)),
        [accountMetrics, linkedLoanAccountIds],
    );

    const [editOpen, setEditOpen] = useState(false);

    // Optimistic ordering layered on top of the server order. Null means "use
    // the server order"; a drag sets the new id order and persists it.
    const [order, setOrder] = useState<string[] | null>(null);
    const orderedAccounts = useMemo(() => {
        if (!order) {
            return manageableAccounts;
        }
        const byId = new Map(manageableAccounts.map((a) => [a.id, a]));
        const ordered = order
            .map((id) => byId.get(id))
            .filter((a) => a !== undefined);
        const rest = manageableAccounts.filter((a) => !order.includes(a.id));
        return [...ordered, ...rest];
    }, [manageableAccounts, order]);

    // Optimistic visibility overrides keyed by account id; falls back to the
    // server flag until the next full reload.
    const [hiddenOverrides, setHiddenOverrides] = useState<
        Record<string, boolean>
    >({});
    const isHidden = useCallback(
        (account: AccountWithMetrics) =>
            hiddenOverrides[account.id] ?? account.hidden_on_dashboard,
        [hiddenOverrides],
    );

    const gridAccounts = useMemo(
        () => orderedAccounts.filter((a) => !isHidden(a)),
        [orderedAccounts, isHidden],
    );

    const handleReorder = useCallback((ids: string[]) => {
        setOrder(ids);
        // Persist only; keep the deferred netWorthEvolution prop in place by
        // requesting an unrelated cheap prop so it isn't refetched (skeleton).
        router.patch(
            reorder.url(),
            { ids },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['showEncryptionPrompt'],
            },
        );
    }, []);

    const handleToggleVisibility = useCallback(
        (id: string, hidden: boolean) => {
            setHiddenOverrides((prev) => ({ ...prev, [id]: hidden }));
            router.patch(
                updateVisibility.url(id),
                { hidden },
                {
                    preserveScroll: true,
                    preserveState: true,
                    only: ['showEncryptionPrompt'],
                },
            );
        },
        [],
    );

    // Build linked loan metrics map keyed by real estate account ID
    const linkedLoanMetricsMap = useMemo(() => {
        const map: Record<
            string,
            {
                currentBalance: number;
                previousBalance: number;
                diff: number;
                history: Array<{ date: string; value: number }>;
                loanAccount?: {
                    name: string;
                    bank: { name: string; logo: string | null } | null;
                };
            }
        > = {};
        accountMetrics.forEach((a) => {
            if (a.type === 'real_estate' && a.linked_loan_account_id) {
                const loan = accountMetrics.find(
                    (l) => l.id === a.linked_loan_account_id,
                );
                if (loan) {
                    map[a.id] = {
                        currentBalance: Math.abs(loan.currentBalance),
                        previousBalance: Math.abs(loan.previousBalance),
                        diff: loan.diff,
                        history: loan.history.map((h) => ({
                            date: h.date,
                            value: Math.abs(h.value),
                        })),
                        loanAccount: {
                            name: loan.name,
                            bank: loan.bank,
                        },
                    };
                }
            }
        });
        return map;
    }, [accountMetrics]);

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

                    <div className="flex items-center justify-between">
                        <h2 className="text-lg font-semibold">
                            {__('Accounts')}
                        </h2>
                        {orderedAccounts.length > 0 && (
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                onClick={() => setEditOpen(true)}
                                aria-label={__('Edit accounts')}
                            >
                                <Pencil className="size-4" />
                            </Button>
                        )}
                    </div>

                    <div className="grid gap-4 md:grid-cols-2">
                        {gridAccounts.map((account) => (
                            <AccountBalanceCard
                                key={account.id}
                                account={account}
                                onBalanceUpdated={refetch}
                                linkedLoanMetrics={
                                    linkedLoanMetricsMap[account.id]
                                }
                                displayCurrencyCode={
                                    netWorthEvolution.currency_code
                                }
                            />
                        ))}
                    </div>

                    <AccountsManagerDialog
                        open={editOpen}
                        onOpenChange={setEditOpen}
                        accounts={orderedAccounts}
                        isHidden={isHidden}
                        onReorder={handleReorder}
                        onToggleVisibility={handleToggleVisibility}
                    />
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
