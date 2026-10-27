import { AiConsentBanner } from '@/components/transactions/ai-consent-banner';
import { TransactionTable } from '@/components/transactions/transaction-table';
import { useAiCategorizationConsent } from '@/hooks/use-ai-categorization-consent';
import { useLocale } from '@/hooks/use-locale';
import {
    useServerTransactionSource,
    type AppliedFilters,
} from '@/hooks/use-server-transaction-source';
import { __ } from '@/utils/i18n';
import { Head } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';

import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import HeadingSmall from '@/components/heading-small';
import { TransactionActionsMenu } from '@/components/transactions/transaction-actions-menu';
import { TransactionExportButton } from '@/components/transactions/transaction-export-button';
import { TransactionFilterSummary } from '@/components/transactions/transaction-filter-summary';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { type CursorPaginatedResponse } from '@/lib/cursor-pagination';
import { isNewSince } from '@/lib/new-transactions';
import { type ServerDayTotal } from '@/lib/transaction-day-groups';
import { describeActiveFilters } from '@/lib/transaction-filter-chips';
import { hasActiveFilters } from '@/lib/transaction-filter-serialization';
import { type BreadcrumbItem } from '@/types';
import {
    filterTransactionalAccounts,
    type Account,
    type Bank,
} from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import {
    type TransactionFilterSummary as FilterSummary,
    type ServerTransaction,
} from '@/types/transaction';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Transactions',
        href: transactionsIndex.url(),
    },
];

interface Props {
    transactions: CursorPaginatedResponse<ServerTransaction>;
    dayTotals: Record<string, ServerDayTotal>;
    /** Deferred, and only sent while a filter is applied. */
    filterSummary?: FilterSummary | null;
    appliedFilters: AppliedFilters;
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    labels: Label[];
    automationRules: AutomationRule[];
    hasAiConsent: boolean;
    aiConsentPromptDismissed: boolean;
    lastVisitAt: string | null;
}

export default function Transactions({
    transactions: serverTransactions,
    dayTotals: initialDayTotals,
    filterSummary,
    appliedFilters,
    categories,
    accounts,
    banks,
    labels: initialLabels,
    automationRules,
    hasAiConsent,
    aiConsentPromptDismissed,
    lastVisitAt,
}: Props) {
    const locale = useLocale();
    const source = useServerTransactionSource({
        transactions: serverTransactions,
        dayTotals: initialDayTotals,
        appliedFilters,
    });
    const { rows: allTransactions, filters } = source;
    const refreshTransactions = source.reload;
    const ai = useAiCategorizationConsent({
        rows: allTransactions,
        refreshRows: refreshTransactions,
        hasAiConsent,
        aiConsentPromptDismissed,
    });

    // Frozen at mount so per-row "new" marks stay stable for the whole visit.
    // The marker is server-side (per user), so it's shared across devices; the
    // controller advances it on load using the newest created_at it served.
    const [lastVisitAtMount] = useState<string | null>(() => lastVisitAt);
    const isNewRow = useCallback(
        (transaction: ServerTransaction) =>
            isNewSince(transaction, lastVisitAtMount),
        [lastVisitAtMount],
    );

    const hasTransactionalAccounts = useMemo(
        () => filterTransactionalAccounts(accounts).length > 0,
        [accounts],
    );

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Transactions')} />

            <div className="space-y-6 p-6">
                <HeadingSmall
                    title={__('Transactions')}
                    description={__('View and manage your transactions')}
                />

                {/* One child, so the dialogs and the bulk bar it mounts after
                    the table never take part in the spacing above. */}
                <div>
                    <TransactionTable
                        source={source}
                        categories={categories}
                        accounts={accounts}
                        banks={banks}
                        labels={initialLabels}
                        automationRules={automationRules}
                        enableSavedFilters
                        categorizingIds={ai.categorizingIds}
                        isNewRow={isNewRow}
                        rowCellClassName="pt-2.5 pb-2"
                        confirmBulkByTyping
                        announceBulkOutcome
                        renderToolbar={({
                            columnPicker,
                            labels,
                            clearSelection,
                        }) => (
                            <div className="flex w-full flex-wrap items-center justify-between gap-2">
                                <TransactionActionsMenu
                                    transactions={allTransactions}
                                    hasTransactionalAccounts={
                                        hasTransactionalAccounts
                                    }
                                    onReEvaluateComplete={() => {
                                        clearSelection();
                                        refreshTransactions();
                                    }}
                                />

                                <div className="ml-auto flex items-center gap-2">
                                    <TransactionExportButton
                                        params={source.queryParams}
                                        loadedIds={allTransactions.map(
                                            (transaction) => transaction.id,
                                        )}
                                        hasMore={source.pagination.hasMore}
                                        filterChips={describeActiveFilters(
                                            filters,
                                            {
                                                categories,
                                                accounts,
                                                labels,
                                                locale,
                                            },
                                        )}
                                    />
                                    {columnPicker}
                                </div>
                            </div>
                        )}
                        aboveRows={
                            hasActiveFilters(filters) ? (
                                <TransactionFilterSummary
                                    // Stale while a new query is on its way.
                                    summary={
                                        source.isLoading
                                            ? undefined
                                            : (filterSummary ?? undefined)
                                    }
                                    filters={filters}
                                    // The dates go without saying beside the
                                    // list they head.
                                    filterDescription={describeActiveFilters(
                                        {
                                            ...filters,
                                            dateFrom: null,
                                            dateTo: null,
                                        },
                                        {
                                            categories,
                                            accounts,
                                            labels: initialLabels,
                                            locale,
                                        },
                                    )}
                                />
                            ) : null
                        }
                        topRow={
                            ai.showBanner ? (
                                <AiConsentBanner
                                    isSaving={ai.isSaving}
                                    onEnable={ai.enable}
                                    onDismiss={ai.dismiss}
                                />
                            ) : null
                        }
                    />
                </div>
            </div>
        </AppSidebarLayout>
    );
}
