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
import { useLocale } from '@/hooks/use-locale';
import { COMPACT_LIST_MUTED_TEXT, DAY_BAND } from '@/lib/compact-list-style';
import {
    type DaySummary,
    type DaySummaryOf,
    type DayTotalState,
    formatDayBand,
    formatDayHeading,
    formatTransactionCount,
} from '@/lib/transaction-day-groups';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { type DecryptedTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import {
    type Row,
    type RowSelectionState,
    type Table,
} from '@tanstack/react-table';
import { type ComponentProps, useCallback } from 'react';

interface TransactionDayHeaderProps {
    table: Table<DecryptedTransaction>;
    date: string;
    /** The loaded rows of this day, which its checkbox ticks. */
    rows: Row<DecryptedTransaction>[];
    /** How many transactions the whole day holds and their total, loaded or not. */
    summary: DaySummary;
    /**
     * The table's selection, handed in as state rather than read off the rows:
     * `row.getIsSelected()` reads a mutable table, which the React Compiler
     * memoizes past, so the checkbox would never move.
     */
    rowSelection: RowSelectionState;
    measureProps: DataTableMeasureProps;
    /** False leaves the day's total out, e.g. for a widget that asks so. */
    showTotal?: boolean;
}

/** The ids of the columns the table draws, virtual ones left out. */
function renderedColumnIds(table: Table<DecryptedTransaction>): string[] {
    return table
        .getVisibleLeafColumns()
        .filter(
            (column) =>
                !(column.columnDef.meta as { isVirtual?: boolean } | undefined)
                    ?.isVirtual,
        )
        .map((column) => column.id);
}

/**
 * The day total fades behind the rows it adds up, without its income
 * highlight, and comes back in full while the day's header is hovered.
 */
const DAY_TOTAL_FADED =
    'opacity-30 transition-[opacity,background-color] duration-200 group-hover/day:opacity-100';

/** The income highlight the faded total gets back on hover. */
const DAY_TOTAL_HOVER_HIGHLIGHT =
    'group-hover/day:bg-green-100/70 dark:group-hover/day:bg-green-900';

export function DayTotal({
    total,
    quiet = false,
}: {
    total: DayTotalState;
    /** Small, grey and signed, without the income highlight: the band's. */
    quiet?: boolean;
}) {
    const currencyCode = usePage<SharedData>().props.auth.user.currency_code;

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
                className={cn('text-xs/[1.4]', COMPACT_LIST_MUTED_TEXT)}
            />
        );
    }

    return (
        <AmountDisplay
            amountInCents={total.amount}
            currencyCode={currencyCode}
            variant="positive-highlight"
            weight="medium"
            monospace
            className={cn(
                DAY_TOTAL_FADED,
                total.amount >= 0 && DAY_TOTAL_HOVER_HIGHLIGHT,
            )}
        />
    );
}

/** Ticks or unticks every loaded row of one day, keeping the rest as they are. */
function setDaySelected(
    table: Table<DecryptedTransaction>,
    rows: Row<DecryptedTransaction>[],
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

/** Which day it is and how many transactions it holds. */
function DayHeading({ date, count }: { date: string; count: number }) {
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
            </span>
        </>
    );
}

/**
 * The row above each day of a transactions table sorted by date: which day it
 * is, how many transactions it holds, their total under the Amount column, and
 * a checkbox that ticks the loaded ones.
 */
export function TransactionDayHeader({
    table,
    date,
    rows,
    summary,
    rowSelection,
    measureProps,
    showTotal = true,
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

    const total = showTotal && <DayTotal total={summary.total} />;

    return (
        <TableRow
            {...measureProps}
            className="group/day bg-muted/50 hover:bg-muted/50 dark:bg-muted/30 dark:hover:bg-muted/30"
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
                    <DayHeading date={date} count={summary.count} />
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
    summary,
    rowSelection,
    selectionActive,
    measureProps,
    showTotal = true,
}: Omit<TransactionDayHeaderProps, 'measureProps'> & {
    selectionActive: boolean;
    measureProps: DataTableMeasureProps;
}) {
    const allSelected = rows.every((row) => rowSelection[row.id]);

    return (
        <DayBand date={date} {...measureProps}>
            {selectionActive ? (
                <button
                    type="button"
                    className="shrink-0 rounded-sm text-xs/[1.4] font-medium text-foreground underline-offset-2 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                    onClick={() => setDaySelected(table, rows, !allSelected)}
                >
                    {allSelected ? __('Deselect day') : __('Select day')}
                </button>
            ) : (
                showTotal && <DayTotal total={summary.total} quiet />
            )}
        </DayBand>
    );
}

/**
 * The band itself, without a table behind it: the day on the left and,
 * on the right, whatever the list puts there (its total, a selection
 * button, or nothing).
 */
export function DayBand({
    date,
    children,
    ...props
}: Omit<ComponentProps<'div'>, 'className'> & { date: string }) {
    const locale = useLocale();

    return (
        <div
            {...props}
            className={cn(DAY_BAND, 'items-baseline')}
            data-testid="transaction-day-header"
        >
            <h3 className="min-w-0 truncate text-xs/[1.4] font-semibold tracking-[0.04em] text-neutral-600 uppercase dark:text-neutral-400">
                {formatDayBand(date, locale)}
            </h3>
            {children}
        </div>
    );
}

const groupByTransactionDate = (transaction: DecryptedTransaction): string =>
    transaction.transaction_date;

/**
 * Everything a list of transactions needs to draw its day headers, so both
 * pages wire them the same way, as table rows or as bands of the compact list.
 */
export function useTransactionDayHeaders({
    table,
    rowSelection,
    daySummaryOf,
    layout,
    selectionActive,
    showTotals = true,
}: {
    table: Table<DecryptedTransaction>;
    rowSelection: RowSelectionState;
    daySummaryOf: DaySummaryOf;
    layout: 'table' | 'list';
    /** Rows are being picked, which the card headers answer to. */
    selectionActive: boolean;
    /** False leaves every day's total out. Default true. */
    showTotals?: boolean;
}): {
    groupRowsBy: (transaction: DecryptedTransaction) => string;
    renderGroupHeader: (
        group: DataTableRowGroup<DecryptedTransaction>,
        measureProps: DataTableMeasureProps,
    ) => React.ReactNode;
} {
    const renderGroupHeader = useCallback(
        (
            group: DataTableRowGroup<DecryptedTransaction>,
            measureProps: DataTableMeasureProps,
        ) => {
            const props = {
                table,
                date: group.key,
                rows: group.rows,
                summary: daySummaryOf(group.key),
                rowSelection,
                measureProps,
                showTotal: showTotals,
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
            daySummaryOf,
            layout,
            selectionActive,
            showTotals,
        ],
    );

    return {
        groupRowsBy: groupByTransactionDate,
        renderGroupHeader,
    };
}
