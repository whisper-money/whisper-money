import { AmountDisplay } from '@/components/ui/amount-display';
import { Checkbox } from '@/components/ui/checkbox';
import {
    type DataTableMeasureProps,
    type DataTableRowGroup,
} from '@/components/ui/data-table';
import { Skeleton } from '@/components/ui/skeleton';
import { TableCell, TableRow } from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useAmountsInUserCurrency } from '@/hooks/use-amounts-in-user-currency';
import { useLocale } from '@/hooks/use-locale';
import {
    type AmountInUserCurrency,
    formatDayBand,
    formatDayHeading,
    formatTransactionCount,
    sumDayTotal,
} from '@/lib/transaction-day-groups';
import { type SharedData } from '@/types';
import { type ServerTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import {
    type Row,
    type RowSelectionState,
    type Table,
} from '@tanstack/react-table';
import { useCallback } from 'react';

interface TransactionDayHeaderProps {
    table: Table<ServerTransaction>;
    date: string;
    /** The loaded rows of this day, which its checkbox ticks. */
    rows: Row<ServerTransaction>[];
    /**
     * The table's selection, handed in as state rather than read off the rows:
     * `row.getIsSelected()` reads a mutable table, which the React Compiler
     * memoizes past, so the checkbox would never move.
     */
    rowSelection: RowSelectionState;
    amountInUserCurrency: AmountInUserCurrency;
    /** More of this day may sit on a page that is not loaded yet. */
    isPartial?: boolean;
    measureProps: DataTableMeasureProps;
}

/** The ids of the columns the table draws, virtual ones left out. */
function renderedColumnIds(table: Table<ServerTransaction>): string[] {
    return table
        .getVisibleLeafColumns()
        .filter(
            (column) =>
                !(column.columnDef.meta as { isVirtual?: boolean } | undefined)
                    ?.isVirtual,
        )
        .map((column) => column.id);
}

function DayTotal({
    rows,
    amountInUserCurrency,
    quiet = false,
}: Pick<TransactionDayHeaderProps, 'rows' | 'amountInUserCurrency'> & {
    /** Small, grey and signed, without the income highlight. */
    quiet?: boolean;
}) {
    const currencyCode = usePage<SharedData>().props.auth.user.currency_code;
    const total = sumDayTotal(
        rows.map((row) => row.original),
        amountInUserCurrency,
    );

    if (total.status === 'pending') {
        return <Skeleton className="ml-auto h-4 w-16" />;
    }

    if (total.status === 'missing') {
        const reason = __(
            'Some amounts of this day could not be converted to :currency',
            { currency: currencyCode },
        );

        return (
            <TooltipProvider>
                <Tooltip>
                    <TooltipTrigger asChild>
                        <span
                            tabIndex={0}
                            aria-label={reason}
                            className="text-muted-foreground"
                        >
                            —
                        </span>
                    </TooltipTrigger>
                    <TooltipContent>{reason}</TooltipContent>
                </Tooltip>
            </TooltipProvider>
        );
    }

    if (quiet) {
        return (
            <AmountDisplay
                amountInCents={total.amount}
                currencyCode={currencyCode}
                showSign
                monospace
                className="text-xs text-neutral-600 dark:text-neutral-400"
            />
        );
    }

    return (
        <AmountDisplay
            amountInCents={total.amount}
            currencyCode={currencyCode}
            variant="positive-highlight"
            highlightPositive={total.amount >= 0}
            weight="medium"
            monospace
        />
    );
}

/** Ticks or unticks every loaded row of one day, keeping the rest as they are. */
function setDaySelected(
    table: Table<ServerTransaction>,
    rows: Row<ServerTransaction>[],
    checked: boolean,
) {
    table.setRowSelection((selection) => {
        const next = { ...selection };

        for (const row of rows) {
            if (checked) {
                next[row.id] = true;
            } else {
                delete next[row.id];
            }
        }

        return next;
    });
}

/** Which day it is and how many of its transactions are loaded. */
function DayHeading({
    date,
    count,
    isPartial,
}: {
    date: string;
    count: number;
    isPartial: boolean;
}) {
    const locale = useLocale();
    const heading = formatDayHeading(date, locale);

    return (
        <>
            <span className="font-semibold text-foreground">
                {heading.name}
            </span>
            <span className="text-muted-foreground">{heading.date}</span>
            <span className="text-xs text-muted-foreground">
                · {formatTransactionCount(count)}
                {isPartial && ` ${__('so far')}`}
            </span>
        </>
    );
}

/**
 * The row above each day of a transactions table sorted by date: which day it
 * is, how many of its transactions are loaded, their total under the Amount
 * column, and a checkbox that ticks all of them.
 */
export function TransactionDayHeader({
    table,
    date,
    rows,
    rowSelection,
    amountInUserCurrency,
    isPartial = false,
    measureProps,
}: TransactionDayHeaderProps) {
    const locale = useLocale();
    const heading = formatDayHeading(date, locale);

    const columnIds = renderedColumnIds(table);
    const hasSelectColumn = columnIds[0] === 'select';
    const amountIndex = columnIds.indexOf('amount');
    const labelStart = hasSelectColumn ? 1 : 0;
    const labelSpan =
        (amountIndex === -1 ? columnIds.length : amountIndex) - labelStart;
    const trailingSpan =
        amountIndex === -1 ? 0 : columnIds.length - amountIndex - 1;

    const selectedCount = rows.filter((row) => rowSelection[row.id]).length;
    const allSelected = selectedCount === rows.length;

    const total = (
        <DayTotal rows={rows} amountInUserCurrency={amountInUserCurrency} />
    );

    return (
        <TableRow
            {...measureProps}
            className="bg-muted/50 hover:bg-muted/50 dark:bg-muted/30 dark:hover:bg-muted/30"
            data-testid="transaction-day-header"
        >
            {hasSelectColumn && (
                <TableCell className="py-2">
                    <Checkbox
                        checked={
                            allSelected ||
                            (selectedCount > 0 && 'indeterminate')
                        }
                        className="ml-2"
                        onCheckedChange={(value) =>
                            setDaySelected(table, rows, value === true)
                        }
                        aria-label={__('Select every transaction of :day', {
                            day: heading.name,
                        })}
                    />
                </TableCell>
            )}
            <TableCell colSpan={labelSpan} className="py-2 pl-2">
                <div className="flex items-baseline gap-2 text-sm">
                    <DayHeading
                        date={date}
                        count={rows.length}
                        isPartial={isPartial}
                    />
                    {amountIndex === -1 && (
                        <span className="ml-auto pr-2">{total}</span>
                    )}
                </div>
            </TableCell>
            {amountIndex !== -1 && (
                <TableCell className="py-2 pl-4 text-right">{total}</TableCell>
            )}
            {trailingSpan > 0 && (
                <TableCell colSpan={trailingSpan} className="py-2" />
            )}
        </TableRow>
    );
}

/**
 * The band above each day of the compact list: the day and its date on the
 * left, its total on the right. While rows are being picked it offers to pick
 * the whole day instead, since the rows carry no checkbox.
 */
export function TransactionDayBand({
    table,
    date,
    rows,
    rowSelection,
    amountInUserCurrency,
    selectionActive,
    measureProps,
}: Omit<TransactionDayHeaderProps, 'measureProps'> & {
    selectionActive: boolean;
    measureProps: DataTableMeasureProps;
}) {
    const locale = useLocale();
    const allSelected = rows.every((row) => rowSelection[row.id]);

    return (
        <div
            {...measureProps}
            className="flex items-baseline justify-between gap-2 border-b border-neutral-100 bg-neutral-50 px-4 pt-2.5 pb-1.5 dark:border-neutral-800 dark:bg-neutral-900"
            data-testid="transaction-day-header"
        >
            <h3 className="min-w-0 truncate text-xs font-semibold tracking-[0.04em] text-neutral-600 uppercase dark:text-neutral-400">
                {formatDayBand(date, locale)}
            </h3>
            {selectionActive ? (
                <button
                    type="button"
                    className="shrink-0 rounded-sm text-xs font-medium text-foreground underline-offset-2 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                    onClick={() => setDaySelected(table, rows, !allSelected)}
                >
                    {allSelected ? __('Deselect day') : __('Select day')}
                </button>
            ) : (
                <DayTotal
                    rows={rows}
                    amountInUserCurrency={amountInUserCurrency}
                    quiet
                />
            )}
        </div>
    );
}

const groupByTransactionDate = (transaction: ServerTransaction): string =>
    transaction.transaction_date;

/**
 * Everything a list of transactions needs to draw its day headers, so both
 * pages wire them the same way, as table rows or as bands of the compact list. Leaves the
 * list flat when it is not grouped by day.
 */
export function useTransactionDayHeaders({
    table,
    rowSelection,
    transactions,
    isGroupedByDay,
    hasMoreRows,
    layout,
    selectionActive,
}: {
    table: Table<ServerTransaction>;
    rowSelection: RowSelectionState;
    /** The loaded rows, in the order the table shows them. */
    transactions: ServerTransaction[];
    isGroupedByDay: boolean;
    /** More rows exist beyond the loaded ones, so the last day may go on. */
    hasMoreRows: boolean;
    layout: 'table' | 'list';
    /** Rows are being picked, which the card headers answer to. */
    selectionActive: boolean;
}): {
    groupRowsBy?: (transaction: ServerTransaction) => string;
    renderGroupHeader: (
        group: DataTableRowGroup<ServerTransaction>,
        measureProps: DataTableMeasureProps,
    ) => React.ReactNode;
} {
    const amountInUserCurrency = useAmountsInUserCurrency(transactions);
    // By date rather than by row: within one day the table may order the rows
    // differently from the list it was handed.
    const lastLoadedDate = transactions.at(-1)?.transaction_date;

    const renderGroupHeader = useCallback(
        (
            group: DataTableRowGroup<ServerTransaction>,
            measureProps: DataTableMeasureProps,
        ) => {
            const props = {
                table,
                date: group.key,
                rows: group.rows,
                rowSelection,
                amountInUserCurrency,
                isPartial: hasMoreRows && group.key === lastLoadedDate,
                measureProps,
            };

            return layout === 'list' ? (
                <TransactionDayBand
                    {...props}
                    selectionActive={selectionActive}
                />
            ) : (
                <TransactionDayHeader {...props} />
            );
        },
        [
            table,
            rowSelection,
            amountInUserCurrency,
            hasMoreRows,
            lastLoadedDate,
            layout,
            selectionActive,
        ],
    );

    return {
        groupRowsBy: isGroupedByDay ? groupByTransactionDate : undefined,
        renderGroupHeader,
    };
}
