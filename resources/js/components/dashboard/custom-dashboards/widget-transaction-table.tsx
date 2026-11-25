import { show as showTransaction } from '@/actions/App/Http/Controllers/Api/TransactionController';
import { TransactionTable } from '@/components/transactions/transaction-table';
import { useInMemoryTransactionSource } from '@/hooks/use-in-memory-transaction-source';
import { fetchJson } from '@/lib/fetch-json';
import { normalizeTransaction } from '@/lib/normalize-transaction';
import { type SharedData } from '@/types';
import { type Account, type Bank } from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import {
    type DecryptedTransaction,
    type ServerTransaction,
} from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { type WidgetTransactionTableProps } from '@/widgets/sdk/transaction-table-host';
import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';

const NO_HIDDEN_COLUMNS: string[] = [];
const ACCOUNT_HIDDEN: string[] = ['account'];

/**
 * The transaction a dialog opens on, as the server holds it (a part of a split
 * with its siblings, which the merge dialog lists): a widget only has the API
 * row, and draws a copy of it.
 */
async function loadTransaction(
    transaction: DecryptedTransaction,
): Promise<DecryptedTransaction> {
    try {
        const { data } = await fetchJson<{ data: ServerTransaction }>(
            showTransaction.url(transaction.id),
        );

        return normalizeTransaction(data);
    } catch (error) {
        toast.error(__('The transaction could not be opened.'));
        throw error;
    }
}

export interface WidgetTransactionTableHostProps extends WidgetTransactionTableProps {
    /** The placement the table is drawn in, which owns its ticked rows. */
    widgetId: string;
    /** The placement whose rows are ticked, if any: one bulk bar per page. */
    selectionOwner: string | null;
    /** Says whether `widgetId` has rows ticked now. */
    onSelectionChange: (widgetId: string, hasSelection: boolean) => void;
    /** Asks the dashboard for its widgets' data again. */
    onChanged: () => void;
}

/**
 * A dashboard widget's `<TransactionList>` as the app's own transactions
 * table over the widget's rows: the category picked in place, rows ticked
 * for the bulk actions bar, the row menus and every dialog, without the
 * filters. After any change (a save, a split or a merge, a delete, a bulk
 * action) the dashboard reloads its widgets' data, which hands the table its
 * rows again.
 */
export default function WidgetTransactionTable({
    transactions,
    pageSize,
    hideAccount,
    displayRow,
    badgeOf,
    hiddenLabelId,
    showDayTotals,
    categories,
    labels,
    widgetId,
    selectionOwner,
    onSelectionChange,
    onChanged,
}: WidgetTransactionTableHostProps) {
    const { accounts, banks, automationRules } = usePage<
        SharedData & {
            accounts: Account[];
            banks: Bank[];
            automationRules: AutomationRule[];
        }
    >().props;
    const source = useInMemoryTransactionSource({
        transactions,
        categories,
        pageSize: pageSize ?? Math.max(transactions.length, 1),
        reload: onChanged,
    });

    // Rows ticked in another widget untick these: a new query key is what
    // clears the table's selection.
    const [selectionsLost, setSelectionsLost] = useState(0);
    const [seenOwner, setSeenOwner] = useState(selectionOwner);

    if (selectionOwner !== seenOwner) {
        setSeenOwner(selectionOwner);

        // Only another widget taking it: this one letting go has nothing left to clear.
        if (seenOwner === widgetId && selectionOwner !== null) {
            setSelectionsLost((count) => count + 1);
        }
    }

    const handleSelectionChange = useCallback(
        (selectedCount: number) =>
            onSelectionChange(widgetId, selectedCount > 0),
        [onSelectionChange, widgetId],
    );

    // A table that goes (the dashboard being arranged) lets go of its rows.
    useEffect(
        () => () => onSelectionChange(widgetId, false),
        [onSelectionChange, widgetId],
    );

    return (
        <TransactionTable
            source={{ ...source, queryKey: selectionsLost }}
            categories={categories}
            accounts={accounts}
            banks={banks}
            labels={labels}
            automationRules={automationRules}
            hideFilters
            hideColumns={hideAccount ? ACCOUNT_HIDDEN : NO_HIDDEN_COLUMNS}
            hiddenLabelId={hiddenLabelId}
            displayRow={displayRow}
            badgeOf={badgeOf}
            showDayTotals={showDayTotals}
            resolveRow={loadTransaction}
            onSplitChanged={onChanged}
            framedOnPhone
            frameless
            portalBulkActionsBar
            onSelectionChange={handleSelectionChange}
            onBalanceUpdated={onChanged}
            onTransactionsChanged={onChanged}
        />
    );
}
