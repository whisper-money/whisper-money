import { AiConsentBanner } from '@/components/transactions/ai-consent-banner';
import { TransactionTable } from '@/components/transactions/transaction-table';
import { useAiCategorizationConsent } from '@/hooks/use-ai-categorization-consent';
import { useLocale } from '@/hooks/use-locale';
import { useIsMobile } from '@/hooks/use-mobile';
import {
    useServerTransactionSource,
    type AppliedFilters,
} from '@/hooks/use-server-transaction-source';
import { __ } from '@/utils/i18n';
import { Head } from '@inertiajs/react';
import { useCallback, useMemo, useState } from 'react';

import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import { TransactionFilterSummary } from '@/components/transactions/transaction-filter-summary';
import { TransactionsHeaderActions } from '@/components/transactions/transactions-header-actions';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { type CursorPaginatedResponse } from '@/lib/cursor-pagination';
import { isNewSince } from '@/lib/new-transactions';
import { type ServerDayTotal } from '@/lib/transaction-day-groups';
import { describeActiveFilters } from '@/lib/transaction-filter-chips';
import { facetChips } from '@/lib/transaction-filter-facets';
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
    type TransactionFilters as Filters,
    type ServerTransaction,
} from '@/types/transaction';

/**
 * The filters in a few words under the phone's summary: the search and each
 * facet as its chip reads. The dates go without saying beside the list they
 * head, and the side ("expenses") is already in the count before them.
 */
function summaryCaption(
    filters: Filters,
    sources: Parameters<typeof facetChips>[1],
): string[] {
    return [
        ...(filters.searchText ? [`“${filters.searchText}”`] : []),
        ...facetChips(filters, sources)
            .filter((chip) => chip.facet !== 'type')
            .map((chip) => chip.value),
    ];
}

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
    const isMobile = useIsMobile();
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

    // The labels come from the table, which knows the ones a dialog created
    // since the page loaded.
    const exportOptionsWith = (labels: Label[]) => ({
        params: source.queryParams,
        loadedIds: allTransactions.map((transaction) => transaction.id),
        hasMore: source.pagination.hasMore,
        filterChips: describeActiveFilters(filters, {
            categories,
            accounts,
            labels,
            locale,
        }),
    });

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Transactions')} />

            <div className="space-y-6 p-6">
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
                        renderToolbar={({ labels, clearSelection }) => (
                            // The page's title heads the filters, its actions
                            // beside it: icon buttons on a phone.
                            <div className="flex items-center gap-3">
                                <h1 className="text-[26px] font-semibold tracking-tight md:text-xl">
                                    {__('Transactions')}
                                </h1>
                                <div className="ml-auto">
                                    <TransactionsHeaderActions
                                        transactions={allTransactions}
                                        hasTransactionalAccounts={
                                            hasTransactionalAccounts
                                        }
                                        onReEvaluateComplete={() => {
                                            clearSelection();
                                            refreshTransactions();
                                        }}
                                        exportOptions={exportOptionsWith(
                                            labels,
                                        )}
                                        compact={isMobile}
                                    />
                                </div>
                            </div>
                        )}
                        aboveRows={
                            // Null is the server saying there is no filter
                            // to sum up; while a query is on its way the
                            // card waits for that query's own summary.
                            hasActiveFilters(filters) &&
                            (source.isLoading || filterSummary !== null) ? (
                                <TransactionFilterSummary
                                    summary={
                                        source.isLoading
                                            ? undefined
                                            : (filterSummary ?? undefined)
                                    }
                                    filters={filters}
                                    filterDescription={summaryCaption(filters, {
                                        categories,
                                        accounts,
                                        labels: initialLabels,
                                        locale,
                                    })}
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
