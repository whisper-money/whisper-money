import { createContext, type ReactNode, useContext } from 'react';

/**
 * Opens the app's edit dialog on one of the user's transactions, by id.
 * Given by the host (the dashboard), never by the widget: the SDK does not
 * export this context, so widget code can neither provide nor read it.
 */
export type OpenTransaction = (transactionId: string) => void;

const WidgetTransactionOpenerContext = createContext<OpenTransaction | null>(
    null,
);

/** Where the host lets the SDK's `<TransactionList>` open its rows. */
export function WidgetTransactionOpenerProvider({
    open,
    children,
}: {
    open: OpenTransaction | null;
    children: ReactNode;
}) {
    return (
        <WidgetTransactionOpenerContext.Provider value={open}>
            {children}
        </WidgetTransactionOpenerContext.Provider>
    );
}

/** Null where rows open nothing: the MCP preview, a dashboard being edited. */
export function useWidgetTransactionOpener(): OpenTransaction | null {
    return useContext(WidgetTransactionOpenerContext);
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

/**
 * The ids of the transactions in a widget's data: the rows of each
 * `GET /transactions` call, listed or as the `{ data }` of `paginate: "all"`.
 * Only these may open a dialog, so a widget cannot reach a transaction its
 * manifest never read by handing `<TransactionList>` a made-up row.
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

/**
 * The host's opener narrowed to the transactions of one widget's data, or
 * null when the host offers none.
 */
export function scopedTransactionOpener(
    open: OpenTransaction | undefined,
    data: Readonly<Record<string, unknown>>,
): OpenTransaction | null {
    if (open === undefined) {
        return null;
    }

    const allowed = transactionIdsIn(data);

    return (transactionId) => {
        if (allowed.has(transactionId)) {
            open(transactionId);
        }
    };
}
