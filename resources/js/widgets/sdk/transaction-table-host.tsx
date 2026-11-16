import { type DisplayRow } from '@/components/transactions/transaction-base-columns';
import { compareNewestFirst } from '@/hooks/use-in-memory-transaction-source';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type DecryptedTransaction } from '@/types/transaction';
import { createContext, type ReactNode, useContext } from 'react';
import { type WidgetAccount } from './context';
import {
    apiTransaction,
    isRecord,
    toHeldTransaction,
} from './transaction-rows';

/**
 * Widget code may have run by the time the functions below are called: what
 * they use of the shared globals is taken here, when the module loads.
 */
const { isArray } = Array;
const { values } = Object;
const { stringify } = JSON;
const NativeMap = Map;
const NativeSet = Set;

/** How `<TransactionList>` wants its rows drawn. */
interface WidgetTransactionListLayout {
    /** Rows drawn at first and added by "Load more"; null draws them all. */
    pageSize: number | null;
    /** The widget hides the account: no Account column, none on a row. */
    hideAccount: boolean;
    /** How each row reads in the widget (its formatters, its `hide`). */
    displayRow: DisplayRow;
    badgeOf: (transaction: DecryptedTransaction) => string | undefined;
    hiddenLabelId: string | undefined;
    showDayTotals: boolean;
}

/**
 * What `<TransactionList>` asks the host's table to draw: which transactions
 * (by id) and how they read. The rows themselves are never the widget's: the
 * host builds each one from the data it fetched (scopedTransactionTable).
 */
export interface WidgetTransactionListRequest extends WidgetTransactionListLayout {
    /** The ids of the rows the widget passed. */
    transactionIds: readonly string[];
}

/** What the host's table draws. */
export interface WidgetTransactionTableProps extends WidgetTransactionListLayout {
    /** The rows, newest first, built from the host's own data. */
    transactions: DecryptedTransaction[];
    /** The user's own categories and labels: nothing a widget made up. */
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

/** The host's table narrowed to one widget's data (scopedTransactionTable). */
export type ScopedWidgetTransactionTable = (
    request: WidgetTransactionListRequest,
) => ReactNode;

const WidgetTransactionTableContext =
    createContext<ScopedWidgetTransactionTable | null>(null);

/** Where the host lets the SDK's `<TransactionList>` act on its rows. */
export function WidgetTransactionTableProvider({
    render,
    children,
}: {
    render: ScopedWidgetTransactionTable | null;
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
export function useWidgetTransactionTable(): ScopedWidgetTransactionTable | null {
    return useContext(WidgetTransactionTableContext);
}

/**
 * The transactions in a widget's data, by id: the rows of each
 * `GET /transactions` call, listed or as the `{ data }` of `paginate: "all"`.
 * Only these reach the host's table, each as the host fetched it.
 */
export function transactionRowsIn(
    data: Readonly<Record<string, unknown>>,
): ReadonlyMap<string, Record<string, unknown>> {
    const rows = new NativeMap<string, Record<string, unknown>>();

    for (const answer of values(data)) {
        const list =
            isRecord(answer) && !isArray(answer) ? answer.data : answer;

        if (!isArray(list)) {
            continue;
        }

        for (const row of list) {
            if (
                isRecord(row) &&
                typeof row.id === 'string' &&
                typeof row.transaction_date === 'string'
            ) {
                rows.set(row.id, row);
            }
        }
    }

    return rows;
}

/** The ids of the transactions in a widget's data (transactionRowsIn). */
export function transactionIdsIn(
    data: Readonly<Record<string, unknown>>,
): ReadonlySet<string> {
    return new NativeSet(transactionRowsIn(data).keys());
}

/** What the host knows about the user, to build rows with. */
export interface WidgetTransactionTableHost {
    accounts: Readonly<Record<string, WidgetAccount>>;
    categories: readonly Category[];
    labels: readonly Label[];
}

/**
 * The host's table narrowed to the transactions of one widget's data, or
 * null when the host offers none. The widget only says which transactions
 * (by id, among those its manifest read) and how they read; every row the
 * table acts on is built from the host's own copy of the data, so a widget
 * cannot have one transaction edited or deleted while another is on screen,
 * nor one its manifest never read. Ids the data lacks are dropped.
 */
export function scopedTransactionTable(
    render: RenderWidgetTransactionTable | undefined,
    data: Readonly<Record<string, unknown>>,
    host: WidgetTransactionTableHost,
): ScopedWidgetTransactionTable | null {
    if (render === undefined) {
        return null;
    }

    const hostRows = transactionRowsIn(data);
    const categories = [...host.categories];
    const labels = [...host.labels];
    const categoryById = new NativeMap(
        categories.map((category) => [category.id, category]),
    );
    // The same ids keep the same list, whichever render asks: the host's
    // table reloads its rows whenever it is handed a new one, local edits and
    // all. A widget's rows and its data change together.
    const built = new NativeMap<string, DecryptedTransaction[]>();

    const rowsFor = (ids: readonly string[]): DecryptedTransaction[] => {
        const key = stringify(ids);
        let transactions = built.get(key);

        if (transactions === undefined) {
            const seen = new NativeSet<string>();

            transactions = [];

            for (const id of ids) {
                const row = hostRows.get(id);
                const held = row && !seen.has(id) ? apiTransaction(row) : null;

                if (held !== null) {
                    seen.add(id);
                    transactions.push(
                        toHeldTransaction(held, host.accounts, categoryById),
                    );
                }
            }

            transactions.sort(compareNewestFirst);
            built.set(key, transactions);
        }

        return transactions;
    };

    return ({ transactionIds, ...layout }) =>
        render({
            ...layout,
            transactions: rowsFor(transactionIds),
            categories,
            labels,
        });
}
