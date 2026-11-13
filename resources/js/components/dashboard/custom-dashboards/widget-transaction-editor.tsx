import { show as showTransaction } from '@/actions/App/Http/Controllers/Api/TransactionController';
import { TransactionDeleteDialogs } from '@/components/transactions/transaction-delete-dialogs';
import { TransactionRowDialogs } from '@/components/transactions/transaction-row-dialogs';
import { normalizeTransaction } from '@/hooks/use-server-transaction-source';
import { useTransactionBulkActions } from '@/hooks/use-transaction-bulk-actions';
import { useTransactionRowEditing } from '@/hooks/use-transaction-row-editing';
import { fetchJson } from '@/lib/fetch-json';
import { type SharedData } from '@/types';
import { type Account, type Bank } from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type ServerTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { type RowSelectionState } from '@tanstack/react-table';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

/** A tap on a widget's row: the transaction to open, and which tap it was. */
export interface WidgetTransactionRequest {
    transactionId: string;
    /** Tapping the same row again opens it again. */
    sequence: number;
}

/**
 * The dialogs a tap on a widget's transaction opens: the same edit dialog,
 * row actions and delete confirmation as a transactions table, through the
 * same hooks. It loads the transaction by id, as the server holds it, since
 * a widget only has the API row (and draws a copy of it). After anything
 * changes the dashboard asks for its widgets' data again.
 */
export default function WidgetTransactionEditor({
    request,
    onChanged,
}: {
    request: WidgetTransactionRequest;
    onChanged: () => void;
}) {
    const { accounts, categories, banks, labels, automationRules } = usePage<
        SharedData & {
            accounts: Account[];
            categories: Category[];
            banks: Bank[];
            labels: Label[];
            automationRules: AutomationRule[];
        }
    >().props;
    const [rows, setRows] = useState<ServerTransaction[]>([]);
    const [rowSelection, setRowSelection] = useState<RowSelectionState>({});

    const editing = useTransactionRowEditing({
        initialLabels: labels,
        setRows,
        onRowUpdated: onChanged,
    });
    const bulk = useTransactionBulkActions({
        rows,
        setRows,
        rowSelection,
        setRowSelection,
        categories,
        labels: editing.labels,
        accounts,
        editing,
        onTransactionsChanged: onChanged,
    });
    const { setEditTransaction } = editing;

    useEffect(() => {
        let current = true;

        fetchJson<{ data: ServerTransaction }>(
            showTransaction.url(request.transactionId),
        ).then(
            ({ data }) => {
                if (current) {
                    const transaction = normalizeTransaction(data);

                    setRows([transaction]);
                    setEditTransaction(transaction);
                }
            },
            () => {
                if (current) {
                    toast.error(__('The transaction could not be opened.'));
                }
            },
        );

        return () => {
            current = false;
        };
    }, [request, setEditTransaction]);

    return (
        <>
            <TransactionRowDialogs
                editing={editing}
                categories={categories}
                accounts={accounts}
                banks={banks}
                automationRules={automationRules}
                onDuplicated={onChanged}
            />
            <TransactionDeleteDialogs dialog={bulk.deleteDialog} />
        </>
    );
}
