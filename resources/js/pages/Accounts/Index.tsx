import {
    index,
    reorder,
} from '@/actions/App/Http/Controllers/AccountController';
import { AccountListCard } from '@/components/accounts/account-list-card';
import { CreateAccountDialog } from '@/components/accounts/create-account-dialog';
import HeadingSmall from '@/components/heading-small';
import { SortableGrid } from '@/components/sortable-grid';
import { Card, CardContent } from '@/components/ui/card';
import { AccountWithMetrics } from '@/hooks/use-dashboard-data';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { BreadcrumbItem, SharedData } from '@/types';
import { Account } from '@/types/account';
import { __ } from '@/utils/i18n';
import { Head, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Accounts',
        href: index().url,
    },
];

interface AccountMetrics {
    currentBalance: number;
    previousBalance: number;
    diff: number;
    investedAmount: number | null;
    history: Array<{
        date: string;
        value: number;
        investedAmount?: number | null;
    }>;
}

interface Props {
    accounts: Account[];
    accountMetrics?: Record<string, AccountMetrics>;
}

export default function AccountsIndex({ accounts, accountMetrics }: Props) {
    const { auth } = usePage<SharedData>().props;
    const isLoading = !accountMetrics;

    // Identify loan account IDs that are linked to a real estate account
    const linkedLoanAccountIds = useMemo(() => {
        const ids = new Set<string>();
        accounts.forEach((account) => {
            if (
                account.type === 'real_estate' &&
                account.linked_loan_account_id
            ) {
                ids.add(account.linked_loan_account_id);
            }
        });
        return ids;
    }, [accounts]);

    const accountsWithMetrics: AccountWithMetrics[] = useMemo(() => {
        return accounts.map((account) => {
            const metrics = accountMetrics?.[account.id];
            return {
                ...account,
                currentBalance: metrics?.currentBalance ?? 0,
                previousBalance: metrics?.previousBalance ?? 0,
                diff: metrics?.diff ?? 0,
                history: metrics?.history ?? [],
                investedAmount: metrics?.investedAmount ?? null,
            };
        });
    }, [accounts, accountMetrics]);

    // Flat list in the user-defined order; loan accounts linked to a real
    // estate account are surfaced inside that account instead.
    const visibleAccounts = useMemo(
        () =>
            accountsWithMetrics.filter(
                (account) =>
                    !(
                        account.type === 'loan' &&
                        linkedLoanAccountIds.has(account.id)
                    ),
            ),
        [accountsWithMetrics, linkedLoanAccountIds],
    );

    // Optimistic ordering layered on top of the server order. Null means "use
    // the server order"; a drag sets the new id order and persists it.
    const [order, setOrder] = useState<string[] | null>(null);
    const orderedAccounts = useMemo(() => {
        if (!order) {
            return visibleAccounts;
        }
        const byId = new Map(visibleAccounts.map((a) => [a.id, a]));
        const ordered = order
            .map((id) => byId.get(id))
            .filter((a) => a !== undefined);
        const rest = visibleAccounts.filter((a) => !order.includes(a.id));
        return [...ordered, ...rest];
    }, [visibleAccounts, order]);

    const handleReorder = useCallback((ids: string[]) => {
        setOrder(ids);
        // Persist and re-sync the canonical order; the deferred accountMetrics
        // prop is left untouched (kept from the current page).
        router.patch(
            reorder.url(),
            { ids },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['accounts'],
            },
        );
    }, []);

    // Build a map of linked loan metrics keyed by real estate account ID
    const linkedLoanMetricsMap = useMemo(() => {
        if (!accountMetrics) return {};
        const map: Record<
            string,
            AccountMetrics & {
                loanAccount?: {
                    name: string;
                    bank: { name: string; logo: string | null } | null;
                };
            }
        > = {};
        accounts.forEach((account) => {
            if (
                account.type === 'real_estate' &&
                account.linked_loan_account_id &&
                accountMetrics[account.linked_loan_account_id]
            ) {
                const loanAccount = accounts.find(
                    (a) => a.id === account.linked_loan_account_id,
                );
                map[account.id] = {
                    ...accountMetrics[account.linked_loan_account_id],
                    loanAccount: loanAccount
                        ? { name: loanAccount.name, bank: loanAccount.bank }
                        : undefined,
                };
            }
        });
        return map;
    }, [accounts, accountMetrics]);

    const handleBalanceUpdated = useCallback(() => {
        router.reload({ only: ['accountMetrics'] });
    }, []);

    const handleAccountCreated = useCallback(() => {
        router.reload();
    }, []);

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Accounts')} />

            <div className="space-y-8 p-6">
                <div className="flex items-center justify-between gap-2">
                    <HeadingSmall
                        title={__('Accounts')}
                        description={__('View and manage your bank accounts')}
                    />
                    <CreateAccountDialog onSuccess={handleAccountCreated} />
                </div>

                <SortableGrid
                    className="grid gap-4 lg:grid-cols-2"
                    items={orderedAccounts}
                    getId={(account) => account.id}
                    onReorder={handleReorder}
                    renderItem={(account, dragHandle) => (
                        <AccountListCard
                            account={account}
                            dragHandle={dragHandle}
                            loading={isLoading}
                            onBalanceUpdated={handleBalanceUpdated}
                            linkedLoanMetrics={linkedLoanMetricsMap[account.id]}
                            displayCurrencyCode={auth.user.currency_code}
                        />
                    )}
                    footer={
                        <CreateAccountDialog
                            onSuccess={handleAccountCreated}
                            trigger={
                                <Card className="cursor-pointer opacity-50 transition-opacity duration-200 hover:opacity-100">
                                    <CardContent className="flex h-full items-center justify-center">
                                        <div className="flex flex-row items-center justify-center gap-1">
                                            <Plus className="mr-2 h-4 w-4" />
                                            {__('Create Account')}
                                        </div>
                                    </CardContent>
                                </Card>
                            }
                        />
                    }
                />

                {accounts.length === 0 && !isLoading && (
                    <div className="flex h-[300px] items-center justify-center text-muted-foreground">
                        {__(
                            'No accounts found. Add your first account in Settings.',
                        )}
                    </div>
                )}
            </div>
        </AppSidebarLayout>
    );
}
