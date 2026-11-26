import {
    EstimateHeader,
    LineHeader,
    LockedCell,
    PlanMonthCell,
    type PlanCellContext,
} from '@/components/forecast/forecast-plan-cells';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { useLocale } from '@/hooks/use-locale';
import { isShort, monthColumnLabel } from '@/lib/forecast/format';
import {
    buildPlanRows,
    groupRowIds,
    type PlanRow,
    type PlanSection,
} from '@/lib/forecast/plan-rows';
import { cn } from '@/lib/utils';
import { type ForecastData, type ForecastMonthColumn } from '@/types/forecast';
import { __ } from '@/utils/i18n';
import {
    getCoreRowModel,
    getExpandedRowModel,
    useReactTable,
    type ColumnDef,
    type ExpandedState,
    type Row,
} from '@tanstack/react-table';
import { ChevronRightIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

interface ForecastPlanTableProps {
    forecast: ForecastData;
    selectedMonth: string | null;
    /** Groups open on the first render, by row id. */
    initiallyExpanded?: string[];
}

const STICKY = 'sticky left-0 z-10 bg-card';

function sectionLabel(section: PlanSection): { name: string; hint: string } {
    switch (section) {
        case 'income':
            return { name: __('Income'), hint: '' };
        case 'expenses':
            return { name: __('Expenses'), hint: __('your main categories') };
        case 'transfers':
            return {
                name: __('Transfers'),
                hint: __(
                    'move money between your accounts; they are neither income nor spend',
                ),
            };
    }
}

function groupName(name: string | null): string {
    return name ?? __('Other spending');
}

function groupHint(row: Extract<PlanRow, { type: 'group' }>): string {
    const count = row.group.items.length;
    const concrete =
        count === 1 ? __('1 concrete') : __(':count concrete', { count });

    return row.group.estimate
        ? __(':concrete + estimated', { concrete })
        : concrete;
}

function ColumnHeader({
    column,
    selected,
    context,
}: {
    column: ForecastMonthColumn;
    selected: boolean;
    context: PlanCellContext & { locale: string };
}) {
    const { formatAmountNumber } = useFormatAmount();
    const projected = context.projectionByMonth[column.month];
    const short =
        projected !== undefined &&
        column.status === 'future' &&
        isShort(projected.min, context.alertThreshold);
    let tag = '';

    if (column.status === 'current') {
        tag = __('IN PROGRESS');
    } else if (column.status === 'locked') {
        tag = __('PRO');
    } else if (short) {
        tag = `⚠ ${formatAmountNumber(projected.min, context.currencyCode, { fractionDigits: 0 })}`;
    }

    return (
        <th
            scope="col"
            className={cn(
                'px-2 py-2.5 text-right font-medium whitespace-nowrap text-muted-foreground',
                (column.status === 'current' || selected) &&
                    'font-semibold text-foreground',
                short && 'font-semibold text-destructive',
                selected && 'bg-muted',
            )}
            data-test={`forecast-column-${column.month}`}
            data-selected={selected || undefined}
        >
            <span className="flex flex-col items-end gap-px">
                <span>{monthColumnLabel(column.month, context.locale)}</span>
                <span className="text-[10px] font-semibold">{tag}</span>
            </span>
        </th>
    );
}

function RowHeader({
    row,
    currencyCode,
}: {
    row: Row<PlanRow>;
    currencyCode: string;
}) {
    const data = row.original;

    switch (data.type) {
        case 'group':
            return (
                <button
                    type="button"
                    onClick={row.getToggleExpandedHandler()}
                    aria-expanded={row.getIsExpanded()}
                    className="flex min-h-8 items-center gap-2 text-left text-[13px] font-semibold"
                >
                    <ChevronRightIcon
                        aria-hidden="true"
                        className={cn(
                            'size-3.5 text-muted-foreground transition-transform',
                            row.getIsExpanded() && 'rotate-90',
                        )}
                    />
                    <span>{groupName(data.group.name)}</span>
                    <span className="text-[11px] font-normal text-muted-foreground">
                        {groupHint(data)}
                    </span>
                </button>
            );
        case 'line':
            return <LineHeader line={data.line} />;
        case 'estimate':
            return (
                <EstimateHeader
                    estimate={data.estimate}
                    currencyCode={currencyCode}
                />
            );
        case 'summary':
            return data.summary === 'minimum' ? (
                <span className="flex flex-col gap-px">
                    <span>{__('Minimum of the month')}</span>
                    <span className="text-[11px] font-normal text-muted-foreground">
                        {__('and if you spend like in your worst months')}
                    </span>
                </span>
            ) : (
                <span className="font-semibold">{__('Closing balance')}</span>
            );
        default:
            return null;
    }
}

function FullWidthRow({ row, colSpan }: { row: PlanRow; colSpan: number }) {
    if (row.type === 'section') {
        const { name, hint } = sectionLabel(row.section);

        return (
            <tr>
                <th
                    scope="colgroup"
                    colSpan={colSpan}
                    className="border-t px-4 pt-4 pb-1.5 text-left text-[11px] font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    <span
                        className={cn(
                            STICKY,
                            'flex items-baseline gap-2 bg-transparent',
                        )}
                    >
                        {name}
                        {hint && (
                            <span className="font-normal tracking-normal normal-case">
                                {hint}
                            </span>
                        )}
                    </span>
                </th>
            </tr>
        );
    }

    if (row.type === 'add') {
        return (
            <tr>
                <td colSpan={colSpan} className="px-4 pb-1.5 pl-10">
                    {/* Becomes a link once lines can be added (T7). */}
                    <span
                        className="text-xs text-muted-foreground"
                        data-test="forecast-add-line"
                    >
                        {__('+ Add something concrete in :group', {
                            group: groupName(row.groupName),
                        })}
                    </span>
                </td>
            </tr>
        );
    }

    return null;
}

/**
 * "Your plan": the forecast of the account month by month, by the user's
 * parent categories. A category opens into its concrete lines and its
 * estimated rest; the month in progress shows what really happened against
 * what was planned. The first column stays in place while the months scroll,
 * and the month picked on the chart is highlighted.
 */
export function ForecastPlanTable({
    forecast,
    selectedMonth,
    initiallyExpanded = [],
}: ForecastPlanTableProps) {
    const locale = useLocale();
    const rows = useMemo(() => buildPlanRows(forecast.plan), [forecast.plan]);
    const groupIds = useMemo(() => groupRowIds(rows), [rows]);
    const [expanded, setExpanded] = useState<ExpandedState>(() =>
        Object.fromEntries(initiallyExpanded.map((id) => [id, true])),
    );
    const context: PlanCellContext & { locale: string } = useMemo(
        () => ({
            locale,
            today: forecast.today,
            currencyCode: forecast.currencyCode,
            alertThreshold: forecast.account.alertThreshold,
            projectionByMonth: Object.fromEntries(
                forecast.projection.map((month) => [month.month, month]),
            ),
        }),
        [locale, forecast],
    );

    const columns = useMemo<ColumnDef<PlanRow>[]>(
        () => [
            { id: 'concept' },
            ...forecast.months.map(
                (column): ColumnDef<PlanRow> => ({ id: column.month }),
            ),
        ],
        [forecast.months],
    );

    const table = useReactTable({
        data: rows,
        columns,
        state: { expanded },
        onExpandedChange: setExpanded,
        getRowId: (row) => row.id,
        getSubRows: (row) => (row.type === 'group' ? row.subRows : undefined),
        getCoreRowModel: getCoreRowModel(),
        getExpandedRowModel: getExpandedRowModel(),
    });

    const allExpanded =
        groupIds.length > 0 &&
        groupIds.every(
            (id) =>
                expanded === true || (expanded as Record<string, boolean>)[id],
        );
    const colSpan = forecast.months.length + 1;

    return (
        <Card className="gap-0 py-0" data-test="forecast-plan">
            <CardHeader className="flex flex-wrap items-center justify-between gap-4 border-b px-4 py-3.5">
                <div className="flex flex-col gap-0.5">
                    <CardTitle>{__('Your plan')}</CardTitle>
                    <CardDescription>
                        {__(
                            'By your categories. Open one to see what is concrete and what is estimated.',
                        )}
                    </CardDescription>
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() =>
                        setExpanded(
                            allExpanded
                                ? {}
                                : Object.fromEntries(
                                      groupIds.map((id) => [id, true]),
                                  ),
                        )
                    }
                    data-test="forecast-toggle-all"
                >
                    {allExpanded ? __('Collapse all') : __('Expand all')}
                </Button>
            </CardHeader>
            <div className="overflow-x-auto">
                <table className="w-full min-w-[900px] border-collapse text-[13px]">
                    <thead>
                        <tr className="border-b">
                            <th
                                scope="col"
                                className={cn(
                                    STICKY,
                                    'min-w-[250px] px-4 py-2.5 text-left font-medium text-muted-foreground',
                                )}
                            >
                                {__('Item')}
                            </th>
                            {forecast.months.map((column) => (
                                <ColumnHeader
                                    key={column.month}
                                    column={column}
                                    selected={column.month === selectedMonth}
                                    context={context}
                                />
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {table.getRowModel().rows.map((row) => {
                            const data = row.original;

                            if (
                                data.type === 'section' ||
                                data.type === 'add'
                            ) {
                                return (
                                    <FullWidthRow
                                        key={row.id}
                                        row={data}
                                        colSpan={colSpan}
                                    />
                                );
                            }

                            const isSummary = data.type === 'summary';
                            const isChild =
                                row.depth > 0 ||
                                (data.type === 'line' &&
                                    data.line.kind === 'transfer');

                            return (
                                <tr
                                    key={row.id}
                                    className={cn(
                                        data.type === 'group' && 'border-t',
                                        isSummary && 'border-t bg-muted/50',
                                    )}
                                    data-test={`forecast-row-${row.id}`}
                                >
                                    <th
                                        scope="row"
                                        className={cn(
                                            STICKY,
                                            'py-1 pr-4 text-left font-normal',
                                            isChild ? 'pl-10' : 'pl-3',
                                            isSummary &&
                                                'bg-muted pl-4 font-medium',
                                        )}
                                    >
                                        <RowHeader
                                            row={row}
                                            currencyCode={forecast.currencyCode}
                                        />
                                    </th>
                                    {forecast.months.map((column) => (
                                        <td
                                            key={column.month}
                                            className={cn(
                                                'p-0.5',
                                                column.status === 'current' &&
                                                    'bg-muted/30',
                                                column.month ===
                                                    selectedMonth && 'bg-muted',
                                            )}
                                        >
                                            {column.status === 'locked' ? (
                                                <LockedCell />
                                            ) : (
                                                <PlanMonthCell
                                                    row={data}
                                                    month={column.month}
                                                    current={
                                                        column.status ===
                                                        'current'
                                                    }
                                                    context={context}
                                                />
                                            )}
                                        </td>
                                    ))}
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </Card>
    );
}
