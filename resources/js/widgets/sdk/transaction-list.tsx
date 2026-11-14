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
import { type Account, type Bank } from '@/types/account';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import {
    type CategorySource,
    type ServerTransaction,
    type TransactionSource,
} from '@/types/transaction';
import { __ } from '@/utils/i18n';
import {
    getCoreRowModel,
    type RowSelectionState,
    useReactTable,
} from '@tanstack/react-table';
import { Fragment, useMemo, useState } from 'react';
import { safeLogoUrl } from './bank-logo';
import { useWidgetSdk, type WidgetAccount } from './context';
import { EmptyState } from './primitives';
import { classNameOf } from './props';
import { isRecord, useWidgetTransactionTable } from './transaction-table-host';

/** The parts of a row a widget may leave out with `hide`. */
const HIDEABLE_PARTS = ['account', 'labels', 'notes'] as const;

type HideablePart = (typeof HIDEABLE_PARTS)[number];

interface NamedRef {
    id: string;
    name: string;
}

/** A row of `GET /transactions` (TransactionResource), the fields read here. */
interface ApiTransaction {
    /** The row as the API answered it, which the formatters receive. */
    raw: Readonly<Record<string, unknown>>;
    id: string;
    transaction_date: string;
    description: string;
    amount: number;
    currency: string;
    account: NamedRef | null;
    category: NamedRef | null;
    category_source: CategorySource | null;
    labels: NamedRef[];
    source: TransactionSource;
    notes: string | null;
    creditor_name: string | null;
    debtor_name: string | null;
    split_parent_id: string | null;
}

function stringOrNull(value: unknown): string | null {
    return typeof value === 'string' ? value : null;
}

function namedRef(value: unknown): NamedRef | null {
    return isRecord(value) &&
        typeof value.id === 'string' &&
        typeof value.name === 'string'
        ? { id: value.id, name: value.name }
        : null;
}

/**
 * One API row, or null when it lacks what a line needs (an id, a date, an
 * amount and its currency): widget data is whatever the widget handed over.
 */
function apiTransaction(value: unknown): ApiTransaction | null {
    if (
        !isRecord(value) ||
        typeof value.id !== 'string' ||
        typeof value.transaction_date !== 'string' ||
        typeof value.amount !== 'number' ||
        !Number.isFinite(value.amount) ||
        typeof value.currency !== 'string'
    ) {
        return null;
    }

    return {
        raw: value,
        id: value.id,
        transaction_date: value.transaction_date,
        description:
            typeof value.description === 'string' ? value.description : '',
        amount: value.amount,
        currency: value.currency,
        account: namedRef(value.account),
        category: namedRef(value.category),
        category_source: (value.category_source ??
            null) as CategorySource | null,
        labels: Array.isArray(value.labels)
            ? value.labels.map(namedRef).filter((label) => label !== null)
            : [],
        source: value.source as TransactionSource,
        notes: stringOrNull(value.notes),
        creditor_name: stringOrNull(value.creditor_name),
        debtor_name: stringOrNull(value.debtor_name),
        split_parent_id: stringOrNull(value.split_parent_id),
    };
}

/** The rows of `transactions`: a list, or a `paginate: "all"` answer. */
function apiTransactions(value: unknown): ApiTransaction[] {
    const rows = isRecord(value) && !Array.isArray(value) ? value.data : value;

    return Array.isArray(rows)
        ? rows.map(apiTransaction).filter((row) => row !== null)
        : [];
}

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

function bankOf(account: WidgetAccount | undefined): Bank | undefined {
    return account?.bankName
        ? {
              id: '',
              user_id: null,
              name: account.bankName,
              logo: safeLogoUrl(account.bankLogo),
          }
        : undefined;
}

/** An API row as the shape the app's own table holds its rows in. */
function toServerTransaction(
    row: ApiTransaction,
    accounts: Readonly<Record<string, WidgetAccount>>,
): ServerTransaction {
    return {
        id: row.id,
        user_id: '',
        account_id: row.account?.id ?? '',
        category_id: row.category?.id ?? null,
        description: row.description,
        transaction_date: row.transaction_date,
        amount: row.amount,
        currency_code: row.currency,
        notes: row.notes,
        source: row.source,
        category_source: row.category_source,
        label_ids: row.labels.map((label) => label.id),
        creditor_name: row.creditor_name,
        debtor_name: row.debtor_name,
        split_parent_id: row.split_parent_id,
        created_at: '',
        updated_at: '',
        // The rows read only the name, the bank its logo and name.
        account: (row.account ?? undefined) as Account | undefined,
        bank:
            row.account && Object.hasOwn(accounts, row.account.id)
                ? bankOf(accounts[row.account.id])
                : undefined,
    } as ServerTransaction;
}

/**
 * How a row reads in the widget: its hidden parts blanked and its
 * description rewritten. A copy for display only: what is edited, and what
 * the edit dialog opens on, is the transaction as the server holds it.
 */
function displayCopy(
    transaction: ServerTransaction,
    hidden: ReadonlySet<HideablePart>,
    description: string | null,
): ServerTransaction {
    return {
        ...transaction,
        description: description ?? transaction.description,
        account: hidden.has('account') ? undefined : transaction.account,
        notes: hidden.has('notes') ? null : transaction.notes,
        label_ids: hidden.has('labels') ? [] : transaction.label_ids,
    };
}

/**
 * The user's categories, plus one for each category a row names that the
 * page does not know (another member's in a shared space), so the row says
 * its name rather than "Uncategorized".
 */
function categoriesFor(
    rows: ApiTransaction[],
    known: readonly Category[],
): Category[] {
    const byId = new Map(known.map((category) => [category.id, category]));

    for (const { category } of rows) {
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
    displayRow: (transaction: ServerTransaction) => ServerTransaction;
    badgeOf: (transaction: ServerTransaction) => string | undefined;
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
    transactions: ServerTransaction[];
    categories: Category[];
    labels: Label[];
    hiddenLabelId: string | undefined;
    badgeOf: (transaction: ServerTransaction) => string | undefined;
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
                    onUnsplit={() => undefined}
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
    transactions: ServerTransaction[];
    pageSize: number | null;
    displayRow: (transaction: ServerTransaction) => ServerTransaction;
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
 * draws the rows.
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
    const held = useMemo(
        () =>
            rows
                .map((row) => toServerTransaction(row, sdk.accounts))
                .sort(compareNewestFirst),
        [rows, sdk.accounts],
    );
    const daySummaryOf = useInMemoryDaySummaries(held);
    const categories = useMemo(
        () => categoriesFor(rows, sdk.categories),
        [rows, sdk.categories],
    );
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
                        ...shared,
                        hideAccount,
                        categories,
                        labels,
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
