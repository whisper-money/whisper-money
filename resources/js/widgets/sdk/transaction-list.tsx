import { createReadOnlyTransactionColumns } from '@/components/transactions/transaction-base-columns';
import {
    DayBand,
    DayTotal,
    useTransactionDayHeaders,
} from '@/components/transactions/transaction-day-header';
import { TransactionListRow } from '@/components/transactions/transaction-list-row';
import { TransactionTableRowCells } from '@/components/transactions/transaction-table-row';
import { Button as AppButton } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import { FixedPrivacyMode } from '@/contexts/privacy-mode-context';
import {
    groupByDate,
    useInMemoryDaySummaries,
} from '@/hooks/use-day-summaries';
import { compareNewestFirst } from '@/hooks/use-in-memory-transaction-source';
import { useTransactionLayout } from '@/hooks/use-transaction-layout';
import { type DaySummaryOf } from '@/lib/transaction-day-groups';
import { cn } from '@/lib/utils';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type DecryptedTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import {
    getCoreRowModel,
    type RowSelectionState,
    useReactTable,
} from '@tanstack/react-table';
import { Fragment, useMemo, useState } from 'react';
import { useWidgetSdk } from './context';
import { EmptyState } from './primitives';
import { classNameOf } from './props';
import {
    type ApiTransaction,
    apiTransactions,
    toHeldTransaction,
} from './transaction-rows';
import { useWidgetTransactionTable } from './transaction-table-host';

/** The parts of a row a widget may leave out with `hide`. */
const HIDEABLE_PARTS = ['account', 'labels', 'notes'] as const;

type HideablePart = (typeof HIDEABLE_PARTS)[number];

/**
 * The parts `hide` names, as a string: a widget writes `hide={['notes']}`
 * inline, a new array on every render, and the rows should not be redrawn
 * for it.
 */
function hiddenPartsKey(value: unknown): string {
    return Array.isArray(value)
        ? HIDEABLE_PARTS.filter((part) => value.includes(part)).join()
        : '';
}

function hiddenPartsOf(key: string): ReadonlySet<HideablePart> {
    return new Set(
        HIDEABLE_PARTS.filter((part) => key.split(',').includes(part)),
    );
}

/**
 * How a row reads in the widget: its hidden parts blanked and its
 * description rewritten. A copy for display only: what is edited, and what
 * the edit dialog opens on, is the transaction as the server holds it. A
 * description the server could not read ('') draws as still encrypted.
 */
function displayCopy(
    transaction: DecryptedTransaction,
    hidden: ReadonlySet<HideablePart>,
    description: string | null,
): DecryptedTransaction {
    const text = description ?? transaction.description;
    const notes = hidden.has('notes') ? null : transaction.notes;

    return {
        ...transaction,
        description: text,
        decryptedDescription: text,
        description_iv: text === '' ? 'encrypted' : null,
        account: hidden.has('account') ? undefined : transaction.account,
        notes,
        decryptedNotes: notes,
        label_ids: hidden.has('labels') ? [] : transaction.label_ids,
    };
}

/**
 * The user's categories, plus one for each category a row names that the
 * page does not know (another member's in a shared space), so the row says
 * its name rather than "Uncategorized". For the read-only list only: the
 * host's editable table gets the user's own categories and labels, never a
 * placeholder built from what the widget passed.
 */
function categoriesFor(
    rows: ApiTransaction[],
    known: readonly Category[],
): Category[] {
    const byId = new Map(known.map((category) => [category.id, category]));
    const named = rows.flatMap((row) => [
        row.category,
        ...row.splits.map((line) => line.category),
    ]);

    for (const category of named) {
        if (category && !byId.has(category.id)) {
            byId.set(category.id, {
                id: category.id,
                name: category.name,
                icon: 'HelpCircle',
                color: 'gray',
                type: 'expense',
                cashflow_direction: 'outflow',
                parent_id: null,
            } as Category);
        }
    }

    return [...byId.values()];
}

/** The user's labels, plus the ones rows carry that the page does not know. */
function labelsFor(rows: ApiTransaction[], known: readonly Label[]): Label[] {
    const byId = new Map(known.map((label) => [label.id, label]));

    for (const label of rows.flatMap((row) => row.labels)) {
        if (!byId.has(label.id)) {
            byId.set(label.id, { ...label, color: 'gray' } as Label);
        }
    }

    return [...byId.values()];
}

/** What a formatter said about a row, kept only when it is a string. */
function formatted(
    formatter: unknown,
    row: ApiTransaction | undefined,
): string | null {
    if (typeof formatter !== 'function' || row === undefined) {
        return null;
    }

    const value: unknown = formatter(row.raw);

    return typeof value === 'string' ? value : null;
}

function positiveInteger(value: unknown): number | null {
    return typeof value === 'number' && Number.isFinite(value) && value >= 1
        ? Math.trunc(value)
        : null;
}

/**
 * How each row reads, from what the widget's formatters said about it (see
 * `formattedKey`): its description, its badge, its hidden parts.
 */
function rowFormatters(
    formattedKey: string,
    hidden: ReadonlySet<HideablePart>,
): {
    displayRow: (transaction: DecryptedTransaction) => DecryptedTransaction;
    badgeOf: (transaction: DecryptedTransaction) => string | undefined;
} {
    const byId = new Map(
        (
            JSON.parse(formattedKey) as [string, string | null, string | null][]
        ).map(([id, description, badge]) => [id, { description, badge }]),
    );

    return {
        displayRow: (transaction) =>
            displayCopy(
                transaction,
                hidden,
                byId.get(transaction.id)?.description ?? null,
            ),
        badgeOf: (transaction) => byId.get(transaction.id)?.badge ?? undefined,
    };
}

/** No row of a read-only table is ever ticked. */
const NO_SELECTION: RowSelectionState = {};

/** What a read-only layout draws its rows with. */
interface ReadOnlyRowsProps {
    /** The rows shown so far, as they read in the widget. */
    transactions: DecryptedTransaction[];
    categories: Category[];
    labels: Label[];
    hiddenLabelId: string | undefined;
    badgeOf: (transaction: DecryptedTransaction) => string | undefined;
    daySummaryOf: DaySummaryOf;
    showDayTotals: boolean;
}

/**
 * The rows as the app's desktop table draws them, with nothing to act on:
 * its Category, Account, Description and Amount columns under a header per
 * day, without the checkbox, the row menu or a picker.
 */
function ReadOnlyTransactionTable({
    transactions,
    categories,
    labels,
    hiddenLabelId,
    badgeOf,
    daySummaryOf,
    showDayTotals,
    showAccount,
}: ReadOnlyRowsProps & { showAccount: boolean }) {
    // Hands the TanStack table to DataTable, a stable object whose rows change
    // underneath: memoized by the compiler, "Show more" would draw nothing new.
    'use no memo';

    const columns = useMemo(
        () =>
            createReadOnlyTransactionColumns({
                categories,
                labels,
                hiddenLabelId,
                badgeOf,
            }),
        [categories, labels, hiddenLabelId, badgeOf],
    );
    const table = useReactTable({
        data: transactions,
        columns,
        getCoreRowModel: getCoreRowModel(),
        getRowId: (transaction) => transaction.id,
        state: {
            rowSelection: NO_SELECTION,
            columnVisibility: { account: showAccount },
        },
    });
    const dayHeaders = useTransactionDayHeaders({
        table,
        rowSelection: NO_SELECTION,
        daySummaryOf,
        layout: 'table',
        selectionActive: false,
        showTotals: showDayTotals,
    });

    return (
        <DataTable
            table={table}
            columns={columns}
            // The widget's card is the frame.
            className="rounded-none border-0"
            renderRow={(row, virtualRow, rowVirtualizer) => (
                <TransactionTableRowCells
                    row={row}
                    virtualRow={virtualRow}
                    rowVirtualizer={rowVirtualizer}
                />
            )}
            {...dayHeaders}
        />
    );
}

/** The rows as the app's compact list draws them on a phone, only to read. */
function ReadOnlyTransactionCompactList({
    transactions,
    categories,
    labels,
    hiddenLabelId,
    badgeOf,
    daySummaryOf,
    showDayTotals,
    closesCard,
}: ReadOnlyRowsProps & {
    /** Nothing follows the last row, so the card draws its hairline. */
    closesCard: boolean;
}) {
    const lastId = transactions.at(-1)?.id;

    return [...groupByDate(transactions)].map(([date, day]) => (
        <Fragment key={date}>
            <DayBand date={date}>
                {showDayTotals && (
                    <DayTotal total={daySummaryOf(date).total} quiet />
                )}
            </DayBand>
            {day.map((transaction) => (
                <TransactionListRow
                    key={transaction.id}
                    transaction={transaction}
                    categories={categories}
                    labels={labels}
                    selected={false}
                    selectionActive={false}
                    badge={badgeOf(transaction)}
                    hiddenLabelId={hiddenLabelId}
                    className={cn(
                        closesCard && transaction.id === lastId && 'border-b-0',
                    )}
                />
            ))}
        </Fragment>
    ));
}

/**
 * The read-only layouts: the desktop table with the room for it (56rem of
 * the widget's own width), the phone's compact list with less, and a
 * "Show more" for `limit`.
 */
function ReadOnlyTransactions({
    transactions,
    pageSize,
    displayRow,
    showAccount,
    className,
    ...rowsProps
}: Omit<ReadOnlyRowsProps, 'transactions'> & {
    transactions: DecryptedTransaction[];
    pageSize: number | null;
    displayRow: (transaction: DecryptedTransaction) => DecryptedTransaction;
    showAccount: boolean;
    className: string | undefined;
}) {
    // Pages, not rows, so a limit the widget changes later still applies.
    const [pages, setPages] = useState(1);
    const [layoutRef, layout] = useTransactionLayout<HTMLDivElement>();
    const shown = pageSize === null ? Infinity : pageSize * pages;
    const visible = transactions.slice(0, shown).map(displayRow);
    const hasMore = visible.length < transactions.length;

    return (
        <div
            ref={layoutRef}
            data-slot="widget-transaction-list"
            data-layout={layout ?? undefined}
            className={cn('flex flex-col', className)}
        >
            {/* Until it is measured, neither: the first commit measures it. */}
            {layout === 'table' && (
                <ReadOnlyTransactionTable
                    {...rowsProps}
                    transactions={visible}
                    showAccount={showAccount}
                />
            )}
            {layout === 'list' && (
                <ReadOnlyTransactionCompactList
                    {...rowsProps}
                    transactions={visible}
                    closesCard={!hasMore}
                />
            )}
            {hasMore && (
                <AppButton
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="m-2"
                    onClick={() => setPages((count) => count + 1)}
                >
                    {__('Show more')}
                </AppButton>
            )}
        </div>
    );
}

/**
 * The transactions of a `GET /transactions` answer drawn as the app's own
 * transactions table, as on /transactions and an account page: newest first,
 * under a header per day with the day's total; the desktop table with the
 * room for it (56rem of the widget's own width), the phone's compact list
 * with less. On a dashboard it is that table in full: the category picked
 * in place, rows ticked for the bulk actions bar, the row menus and the edit
 * dialog, the widget's data reloaded after each change. Where nothing can be
 * edited (`readOnly`, the MCP preview, a dashboard being arranged) it only
 * draws the rows. The editable table only takes the ids of the rows (and
 * the formatters' text) from the widget: each row it acts on is the host's
 * own copy of that transaction (scopedTransactionTable).
 *
 * - `transactions`: the rows, or the `{ data, truncated }` of `paginate: "all"`.
 * - `hide`: parts to leave out of every row: `'account'`, `'labels'`, `'notes'`.
 * - `limit`: rows drawn at first; a button adds as many again.
 * - `description(row)`: the text a row shows in place of its description.
 * - `badge(row)`: a short tag after the description, or null for none.
 * - `hiddenLabelId`: a label every row carries, left out of each.
 * - `showDayTotals`: false leaves the day headers without their total.
 * - `emptyTitle` / `emptyDescription`: what an empty list says.
 * - `readOnly`: true only draws the rows: nothing to edit, pick or open.
 */
export function TransactionList({
    transactions,
    hide,
    limit,
    description,
    badge,
    hiddenLabelId,
    showDayTotals = true,
    emptyTitle,
    emptyDescription,
    readOnly,
    className,
}: {
    transactions: unknown;
    hide?: unknown;
    limit?: unknown;
    description?: unknown;
    badge?: unknown;
    hiddenLabelId?: unknown;
    showDayTotals?: unknown;
    emptyTitle?: unknown;
    emptyDescription?: unknown;
    readOnly?: unknown;
    className?: unknown;
}) {
    const sdk = useWidgetSdk();
    const hostTable = useWidgetTransactionTable();
    const renderTable = readOnly === true ? null : hostTable;
    const hiddenKey = hiddenPartsKey(hide);

    const rows = useMemo(() => apiTransactions(transactions), [transactions]);
    const categories = useMemo(
        () => categoriesFor(rows, sdk.categories),
        [rows, sdk.categories],
    );
    const held = useMemo(() => {
        const categoryById = new Map(
            categories.map((category) => [category.id, category]),
        );

        return rows
            .map((row) => toHeldTransaction(row, sdk.accounts, categoryById))
            .sort(compareNewestFirst);
    }, [rows, sdk.accounts, categories]);
    const daySummaryOf = useInMemoryDaySummaries(held);
    const labels = useMemo(
        () => labelsFor(rows, sdk.labels),
        [rows, sdk.labels],
    );
    // What the formatters say, as a string: a widget writes them inline, new
    // functions on every render, and the table must not rebuild its columns
    // (remounting every cell, a ticked checkbox with them) for that.
    const formattedKey = JSON.stringify(
        rows.map((row) => [
            row.id,
            formatted(description, row),
            formatted(badge, row),
        ]),
    );
    const { displayRow, badgeOf } = useMemo(
        () => rowFormatters(formattedKey, hiddenPartsOf(hiddenKey)),
        [formattedKey, hiddenKey],
    );

    if (held.length === 0) {
        return (
            <EmptyState
                className={className}
                title={
                    typeof emptyTitle === 'string'
                        ? emptyTitle
                        : __('No transactions')
                }
                description={emptyDescription}
            />
        );
    }

    const shared = {
        transactions: held,
        pageSize: positiveInteger(limit),
        displayRow,
        badgeOf,
        hiddenLabelId:
            typeof hiddenLabelId === 'string' ? hiddenLabelId : undefined,
        showDayTotals: showDayTotals !== false,
    };
    const hideAccount = hiddenKey.split(',').includes('account');

    return (
        <FixedPrivacyMode enabled={sdk.env.privacyMode}>
            {renderTable ? (
                <div
                    data-slot="widget-transaction-list"
                    className={classNameOf(className)}
                >
                    {renderTable({
                        transactionIds: held.map(({ id }) => id),
                        pageSize: shared.pageSize,
                        displayRow,
                        badgeOf,
                        hiddenLabelId: shared.hiddenLabelId,
                        showDayTotals: shared.showDayTotals,
                        hideAccount,
                    })}
                </div>
            ) : (
                <ReadOnlyTransactions
                    {...shared}
                    categories={categories}
                    labels={labels}
                    daySummaryOf={daySummaryOf}
                    showAccount={!hideAccount}
                    className={classNameOf(className)}
                />
            )}
        </FixedPrivacyMode>
    );
}
