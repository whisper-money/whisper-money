import { AmountDisplay } from '@/components/ui/amount-display';
import { Checkbox } from '@/components/ui/checkbox';
import { type DataTableMeasureProps } from '@/components/ui/data-table';
import { Skeleton } from '@/components/ui/skeleton';
import { TableCell, TableRow } from '@/components/ui/table';
import { useLocale } from '@/hooks/use-locale';
import {
    type AmountInUserCurrency,
    formatDayHeading,
    formatTransactionCount,
    sumDayTotal,
} from '@/lib/transaction-day-groups';
import { type SharedData } from '@/types';
import { type ServerTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { type Row, type Table } from '@tanstack/react-table';

interface TransactionDayHeaderProps {
    table: Table<ServerTransaction>;
    date: string;
    /** The loaded rows of this day, which its checkbox ticks. */
    rows: Row<ServerTransaction>[];
    amountInUserCurrency: AmountInUserCurrency;
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
}: Pick<TransactionDayHeaderProps, 'rows' | 'amountInUserCurrency'>) {
    const currencyCode = usePage<SharedData>().props.auth.user.currency_code;
    const total = sumDayTotal(
        rows.map((row) => row.original),
        amountInUserCurrency,
    );

    if (total.status === 'pending') {
        return <Skeleton className="ml-auto h-4 w-16" />;
    }

    if (total.status === 'missing') {
        return (
            <span
                className="text-muted-foreground"
                title={__(
                    'Some amounts of this day could not be converted to :currency',
                    { currency: currencyCode },
                )}
            >
                —
            </span>
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

/**
 * The row above each day of a transactions table sorted by date: which day it
 * is, how many of its transactions are loaded, their total under the Amount
 * column, and a checkbox that ticks all of them.
 */
export function TransactionDayHeader({
    table,
    date,
    rows,
    amountInUserCurrency,
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

    const selectedCount = rows.filter((row) => row.getIsSelected()).length;
    const allSelected = selectedCount === rows.length;

    function toggleDay(checked: boolean) {
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
                        onCheckedChange={(value) => toggleDay(value === true)}
                        aria-label={__('Select every transaction of :day', {
                            day: heading.name,
                        })}
                    />
                </TableCell>
            )}
            <TableCell colSpan={labelSpan} className="py-2 pl-2">
                <div className="flex items-baseline gap-2 text-sm">
                    <span className="font-semibold text-foreground">
                        {heading.name}
                    </span>
                    <span className="text-muted-foreground">
                        {heading.date}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        · {formatTransactionCount(rows.length)}
                    </span>
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
