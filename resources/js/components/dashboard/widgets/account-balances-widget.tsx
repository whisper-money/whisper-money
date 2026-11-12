import {
    reorder,
    updateVisibility,
} from '@/actions/App/Http/Controllers/AccountController';
import { AccountBalanceCard } from '@/components/dashboard/account-balance-card';
import { AccountsManagerDialog } from '@/components/dashboard/accounts-manager-dialog';
import { Button } from '@/components/ui/button';
import { PrivacyRevealScope } from '@/contexts/privacy-mode-context';
import {
    deriveAccountMetrics,
    withCreditCardUsage,
    type AccountWithMetrics,
} from '@/hooks/use-dashboard-data';
import { useLocale } from '@/hooks/use-locale';
import { __ } from '@/utils/i18n';
import { Deferred, router } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { netWorthEvolutionOf } from './net-worth-evolution';
import { type SystemWidgetProps } from './types';

interface LinkedLoanMetrics {
    currentBalance: number;
    previousBalance: number;
    diff: number;
    history: Array<{ date: string; value: number }>;
    loanAccount?: {
        name: string;
        bank: { name: string; logo: string | null } | null;
    };
}

export function AccountBalancesWidget({ data }: SystemWidgetProps) {
    return (
        <Deferred
            data="netWorthEvolution"
            fallback={
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
            }
        >
            <AccountBalances data={data} />
        </Deferred>
    );
}

/**
 * Split from the widget so its hooks only run once the deferred net worth has
 * arrived, the way the page ran them inside its own `Deferred`.
 */
function AccountBalances({ data }: Pick<SystemWidgetProps, 'data'>) {
    const locale = useLocale();
    const netWorthEvolution = netWorthEvolutionOf(data);

    const accountMetrics = useMemo(
        () =>
            deriveAccountMetrics(netWorthEvolution, locale).map((account) =>
                withCreditCardUsage(account, data.creditCardUsage, locale),
            ),
        [netWorthEvolution, data.creditCardUsage, locale],
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

    // Archived accounts stay in the payload so the chart keeps their history,
    // but they are off the dashboard itself: no card, no row in the manager.
    const manageableAccounts = useMemo(
        () =>
            accountMetrics.filter(
                (a) => !linkedLoanAccountIds.has(a.id) && !a.archived_at,
            ),
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
        // `name` is the shared `config('app.name')` string: a constant, so
        // asking for it costs nothing and touches no user data.
        router.patch(
            reorder.url(),
            { ids },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['name'],
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
                    only: ['name'],
                },
            );
        },
        [],
    );

    // Build linked loan metrics map keyed by real estate account ID
    const linkedLoanMetricsMap = useMemo(() => {
        const map: Record<string, LinkedLoanMetrics> = {};
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

    // A balance edited on a card moves every figure the dashboard draws.
    const refetch = useCallback(() => {
        router.reload({
            only: [
                'netWorthEvolution',
                'creditCardUsage',
                'topCategories',
                'topLabels',
                'spendingWindow',
                'cashflowSummary',
            ],
        });
    }, []);

    return (
        <>
            <div className="flex items-center justify-between">
                <h2 className="text-lg font-semibold">{__('Accounts')}</h2>
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
                    <PrivacyRevealScope key={account.id}>
                        <AccountBalanceCard
                            account={account}
                            onBalanceUpdated={refetch}
                            linkedLoanMetrics={linkedLoanMetricsMap[account.id]}
                            displayCurrencyCode={
                                netWorthEvolution.currency_code
                            }
                        />
                    </PrivacyRevealScope>
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
        </>
    );
}
