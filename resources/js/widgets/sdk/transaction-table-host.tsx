import { type DisplayRow } from '@/components/transactions/transaction-base-columns';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type ServerTransaction } from '@/types/transaction';
import { createContext, type ReactNode, useContext } from 'react';

/** What `<TransactionList>` hands the host's transactions table. */
export interface WidgetTransactionTableProps {
    /** The widget's rows, newest first, as the API answered them. */
    transactions: ServerTransaction[];
    /** Rows drawn at first and added by "Load more"; null draws them all. */
    pageSize: number | null;
    /** The widget hides the account: no Account column, none on a row. */
    hideAccount: boolean;
    /** How each row reads in the widget (its formatters, its `hide`). */
    displayRow: DisplayRow;
    badgeOf: (transaction: ServerTransaction) => string | undefined;
    hiddenLabelId: string | undefined;
    showDayTotals: boolean;
    /** The user's categories and labels, plus any a row names that the page does not know. */
    categories: Category[];
    labels: Label[];
}

/**
 * Draws a widget's transactions as the app's own transactions table, with
 * its inline editing, selection, bulk actions, menus and dialogs. Given by
 * the host (the dashboard), never by the widget: the SDK does not export this
 * context, so widget code can neither provide nor read it.
 */
export type RenderWidgetTransactionTable = (
    props: WidgetTransactionTableProps,
) => ReactNode;

const WidgetTransactionTableContext =
    createContext<RenderWidgetTransactionTable | null>(null);

/** Where the host lets the SDK's `<TransactionList>` act on its rows. */
export function WidgetTransactionTableProvider({
    render,
    children,
}: {
    render: RenderWidgetTransactionTable | null;
    children: ReactNode;
}) {
    return (
        <WidgetTransactionTableContext.Provider value={render}>
            {children}
        </WidgetTransactionTableContext.Provider>
    );
}

/**
 * Null where rows can only be read: the MCP preview, a dashboard being
 * edited.
 */
export function useWidgetTransactionTable(): RenderWidgetTransactionTable | null {
    return useContext(WidgetTransactionTableContext);
}

export function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

/**
 * The ids of the transactions in a widget's data: the rows of each
 * `GET /transactions` call, listed or as the `{ data }` of `paginate: "all"`.
 * Only these reach the host's table.
 */
export function transactionIdsIn(
    data: Readonly<Record<string, unknown>>,
): ReadonlySet<string> {
    const ids = new Set<string>();

    for (const answer of Object.values(data)) {
        const rows =
            isRecord(answer) && !Array.isArray(answer) ? answer.data : answer;

        if (!Array.isArray(rows)) {
            continue;
        }

        for (const row of rows) {
            if (
                isRecord(row) &&
                typeof row.id === 'string' &&
                typeof row.transaction_date === 'string'
            ) {
                ids.add(row.id);
            }
        }
    }

    return ids;
}

const scopedRows = new WeakMap<ServerTransaction[], ServerTransaction[]>();

/**
 * The host's table narrowed to the transactions of one widget's data, or
 * null when the host offers none. A widget cannot have a transaction its
 * manifest never read edited by handing `<TransactionList>` a made-up row.
 */
export function scopedTransactionTable(
    render: RenderWidgetTransactionTable | undefined,
    data: Readonly<Record<string, unknown>>,
): RenderWidgetTransactionTable | null {
    if (render === undefined) {
        return null;
    }

    const allowed = transactionIdsIn(data);

    return (props) => {
        // The same rows keep the same list, whichever render asks: the host's
        // table reloads its rows whenever it is handed a new one, local edits
        // and all. A widget's rows and its data change together.
        let transactions = scopedRows.get(props.transactions);

        if (transactions === undefined) {
            transactions = props.transactions.filter((transaction) =>
                allowed.has(transaction.id),
            );
            scopedRows.set(props.transactions, transactions);
        }

        return render({ ...props, transactions });
    };
}
