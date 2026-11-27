import {
    useForecastEditor,
    type ForecastEditTarget,
} from '@/components/forecast/forecast-editor';
import {
    EMPTY_CELL,
    EstimateHeader,
    KindBadge,
    LineHeader,
    LockedCell,
    PlanCell,
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
import { isAdjustableEstimate } from '@/lib/forecast/edits';
import {
    isShort,
    monthColumnLabel,
    monthLongName,
} from '@/lib/forecast/format';
import {
    buildPlanRows,
    groupName,
    groupRowIds,
    type PlanRow,
    type PlanSection,
} from '@/lib/forecast/plan-rows';
import { cn } from '@/lib/utils';
import {
    type ForecastData,
    type ForecastGroup,
    type ForecastMonthColumn,
    type ForecastTransferSuggestion,
} from '@/types/forecast';
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
import {
    createContext,
    useContext,
    useMemo,
    useState,
    type ReactNode,
} from 'react';

interface ForecastPlanTableProps {
    forecast: ForecastData;
    selectedMonth: string | null;
    /** Groups open on the first render, by row id. */
    initiallyExpanded?: string[];
    /** Shown next to "Expand all": the review's link. */
    headerAction?: ReactNode;
}

export const STICKY = 'sticky left-0 z-10 bg-card';

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

/** "2 concrete + estimated". */
export function concreteHint(count: number, estimated: boolean): string {
    const concrete =
        count === 1 ? __('1 concrete') : __(':count concrete', { count });

    return estimated ? __(':concrete + estimated', { concrete }) : concrete;
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

    return (
        <MonthHeader
            column={column}
            selected={selected}
            locale={context.locale}
            warning={
                short
                    ? `⚠ ${formatAmountNumber(projected.min, context.currencyCode, { fractionDigits: 0 })}`
                    : null
            }
        />
    );
}

/** A month's column heading: the month, and "IN PROGRESS", "PRO" or a warning under it. */
export function MonthHeader({
    column,
    selected,
    locale,
    warning,
}: {
    column: ForecastMonthColumn;
    selected: boolean;
    locale: string;
    /** What a future month that runs short says, or null. */
    warning: string | null;
}) {
    const short = warning !== null && column.status === 'future';
    let tag = '';

    if (column.status === 'current') {
        tag = __('IN PROGRESS');
    } else if (column.status === 'locked') {
        tag = __('PRO');
    } else if (short) {
        tag = warning;
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
                <span>{monthColumnLabel(column.month, locale)}</span>
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
                    <span>{groupName(data.group)}</span>
                    <span className="text-[11px] font-normal text-muted-foreground">
                        {concreteHint(
                            data.group.items.length,
                            data.group.estimate !== null,
                        )}
                    </span>
                </button>
            );
        case 'line':
            return <LineHeader line={data.line} currencyCode={currencyCode} />;
        case 'suggestion':
            return <SuggestionHeader suggestion={data.suggestion} />;
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

/** A section's heading across the table: "INCOME", "EXPENSES your main categories". */
export function SectionHeading({
    name,
    hint,
    colSpan,
}: {
    name: string;
    hint: string;
    colSpan: number;
}) {
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

function FullWidthRow({ row, colSpan }: { row: PlanRow; colSpan: number }) {
    if (row.type === 'section') {
        return (
            <SectionHeading {...sectionLabel(row.section)} colSpan={colSpan} />
        );
    }

    if (row.type === 'add') {
        return (
            <AddLineRow
                section={row.section}
                group={row.group}
                colSpan={colSpan}
            />
        );
    }

    return null;
}

/** "+ Add something concrete in Home", under a category's lines. */
export function AddLineRow({
    section,
    group,
    colSpan,
}: {
    section: 'income' | 'expenses';
    group: Pick<ForecastGroup, 'name' | 'categoryId' | 'section'>;
    colSpan: number;
}) {
    return (
        <tr>
            <td colSpan={colSpan} className="px-4 pb-1.5 pl-10">
                <AddLineButton section={section} group={group} />
            </td>
        </tr>
    );
}

export function AddLineButton({
    section,
    group,
}: {
    section: 'income' | 'expenses';
    group: Pick<ForecastGroup, 'name' | 'categoryId' | 'section'>;
}) {
    const editor = useForecastEditor();
    const label = __('+ Add something concrete in :group', {
        group: groupName(group),
    });

    if (editor === null) {
        return <span className="text-xs text-muted-foreground">{label}</span>;
    }

    return (
        <button
            type="button"
            className="text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
            onClick={() =>
                editor.open({
                    kind: 'add',
                    prefill: {
                        type: section === 'income' ? 'income' : 'expense',
                        categoryId: group.categoryId,
                    },
                })
            }
            data-test="forecast-add-line"
        >
            {label}
        </button>
    );
}

function useAccountName(accountId: string): string {
    const forecast = useContext(ForecastTableContext);

    return (
        forecast?.accounts.find((account) => account.id === accountId)?.name ??
        ''
    );
}

function SuggestionHeader({
    suggestion,
}: {
    suggestion: ForecastTransferSuggestion;
}) {
    const locale = useLocale();
    const from = useAccountName(suggestion.fromAccountId);

    return (
        <span className="flex flex-col gap-1">
            <span className="text-[13px]">← {from}</span>
            <span className="flex flex-wrap items-center gap-1.5">
                <KindBadge kind="transfer" />
                <span className="text-[11px] text-muted-foreground">
                    {__('Suggested for :month', {
                        month: monthLongName(suggestion.month, locale),
                    })}
                </span>
            </span>
        </span>
    );
}

/** The transfer the warning suggests, prefilled: what "+500?" and the warning's button open. */
export function suggestedTransfer(
    suggestion: ForecastTransferSuggestion,
    accountId: string,
): ForecastEditTarget {
    return {
        kind: 'add',
        prefill: {
            type: 'transfer',
            accountId: suggestion.fromAccountId,
            destinationAccountId: accountId,
            amount: suggestion.amount,
            date: suggestion.date,
            frequency: 'once',
        },
    };
}

/** What a cell opens when it can be changed: closed, locked and paid months open nothing. */
function editTarget(
    row: PlanRow,
    column: ForecastMonthColumn,
    forecast: ForecastData,
): ForecastEditTarget | null {
    if (column.status === 'locked') {
        return null;
    }

    const current = column.status === 'current';

    switch (row.type) {
        case 'line':
            return row.line.occurrenceDates[column.month] &&
                (!current || row.line.current.status === 'pending')
                ? { kind: 'line', line: row.line, month: column.month }
                : null;
        case 'estimate':
            return isAdjustableEstimate(row.group)
                ? { kind: 'estimate', group: row.group, month: column.month }
                : null;
        case 'suggestion':
            return row.suggestion.month === column.month
                ? suggestedTransfer(row.suggestion, forecast.account.id)
                : null;
        default:
            return null;
    }
}

function rowLabel(row: PlanRow): string {
    switch (row.type) {
        case 'line':
            return row.line.name;
        case 'estimate':
            return __('Estimated rest of :group', {
                group: groupName(row.group),
            });
        default:
            return __('Suggested transfer');
    }
}

function SuggestionCell({
    suggestion,
    month,
    currencyCode,
}: {
    suggestion: ForecastTransferSuggestion;
    month: string;
    currencyCode: string;
}) {
    const { formatAmountNumber } = useFormatAmount();

    if (suggestion.month !== month) {
        return EMPTY_CELL;
    }

    return (
        <PlanCell className="border border-dashed border-warning bg-warning/10 text-warning">
            +
            {formatAmountNumber(suggestion.amount, currencyCode, {
                fractionDigits: 0,
            })}
            ?
        </PlanCell>
    );
}

/** A month's cell, a button when the figure can be changed. */
function MonthCell({
    row,
    column,
    context,
}: {
    row: PlanRow;
    column: ForecastMonthColumn;
    context: PlanCellContext & { locale: string };
}) {
    const editor = useForecastEditor();
    const forecast = useContext(ForecastTableContext);

    if (column.status === 'locked') {
        return <LockedCell />;
    }

    const content =
        row.type === 'suggestion' ? (
            <SuggestionCell
                suggestion={row.suggestion}
                month={column.month}
                currencyCode={context.currencyCode}
            />
        ) : (
            <PlanMonthCell
                row={row}
                month={column.month}
                current={column.status === 'current'}
                context={context}
            />
        );
    const target =
        editor === null || forecast === null
            ? null
            : editTarget(row, column, forecast);

    if (target === null || editor === null) {
        return content;
    }

    return (
        <EditableCell
            label={`${rowLabel(row)} · ${monthLongName(column.month, context.locale)}`}
            onOpen={() => editor.open(target)}
            testId={`forecast-cell-${row.id}-${column.month}`}
        >
            {content}
        </EditableCell>
    );
}

/** A cell whose figure can be changed: a button that opens its sheet. */
export function EditableCell({
    label,
    onOpen,
    testId,
    children,
}: {
    label: string;
    onOpen: () => void;
    testId: string;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            className="flex w-full rounded-md hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            aria-label={label}
            onClick={onOpen}
            data-test={testId}
        >
            {children}
        </button>
    );
}

const ForecastTableContext = createContext<ForecastData | null>(null);

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
    headerAction,
}: ForecastPlanTableProps) {
    const locale = useLocale();
    const rows = useMemo(
        () => buildPlanRows(forecast.plan, forecast.alert.transfer),
        [forecast.plan, forecast.alert.transfer],
    );
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
        <ForecastTableContext.Provider value={forecast}>
            <PlanTableCard
                title={__('Your plan')}
                description={__(
                    'By your categories. Open one to see what is concrete and what is estimated; tap a figure to change it.',
                )}
                allExpanded={allExpanded}
                headerAction={headerAction}
                onToggleAll={() =>
                    setExpanded(
                        allExpanded
                            ? {}
                            : Object.fromEntries(
                                  groupIds.map((id) => [id, true]),
                              ),
                    )
                }
                columns={forecast.months.map((column) => (
                    <ColumnHeader
                        key={column.month}
                        column={column}
                        selected={column.month === selectedMonth}
                        context={context}
                    />
                ))}
            >
                {table.getRowModel().rows.map((row) => {
                    const data = row.original;

                    if (data.type === 'section' || data.type === 'add') {
                        return (
                            <FullWidthRow
                                key={row.id}
                                row={data}
                                colSpan={colSpan}
                            />
                        );
                    }

                    const isChild =
                        row.depth > 0 ||
                        data.type === 'suggestion' ||
                        (data.type === 'line' && data.line.kind === 'transfer');

                    return (
                        <PlanRowShell
                            key={row.id}
                            id={row.id}
                            look={
                                data.type === 'summary'
                                    ? 'summary'
                                    : data.type === 'group'
                                      ? 'group'
                                      : isChild
                                        ? 'child'
                                        : 'plain'
                            }
                            header={
                                <RowHeader
                                    row={row}
                                    currencyCode={forecast.currencyCode}
                                />
                            }
                            months={forecast.months}
                            selectedMonth={selectedMonth}
                            cell={(column) => (
                                <MonthCell
                                    row={data}
                                    column={column}
                                    context={context}
                                />
                            )}
                        />
                    );
                })}
            </PlanTableCard>
        </ForecastTableContext.Provider>
    );
}

/**
 * The plan's card: its title, "Expand all", and the table with the first
 * column held in place while the months scroll.
 */
export function PlanTableCard({
    title,
    description,
    allExpanded,
    onToggleAll,
    headerAction,
    columns,
    children,
}: {
    title: string;
    description: string;
    allExpanded: boolean;
    onToggleAll: () => void;
    /** Next to "Expand all": the link to the review, when there is something new. */
    headerAction?: ReactNode;
    /** The month headings. */
    columns: ReactNode;
    children: ReactNode;
}) {
    return (
        <Card className="gap-0 py-0" data-test="forecast-plan">
            <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-4 border-b px-4 py-3.5">
                <div className="flex flex-col gap-0.5">
                    <CardTitle>{title}</CardTitle>
                    <CardDescription>{description}</CardDescription>
                </div>
                <div className="flex flex-wrap items-center gap-3">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={onToggleAll}
                        data-test="forecast-toggle-all"
                    >
                        {allExpanded ? __('Collapse all') : __('Expand all')}
                    </Button>
                    {headerAction}
                </div>
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
                            {columns}
                        </tr>
                    </thead>
                    <tbody>{children}</tbody>
                </table>
            </div>
        </Card>
    );
}

/** A row of the plan with a figure per month: a category, a line or a summary. */
export function PlanRowShell({
    id,
    look,
    header,
    months,
    selectedMonth,
    cell,
}: {
    id: string;
    look: 'group' | 'child' | 'summary' | 'plain';
    header: ReactNode;
    months: ForecastMonthColumn[];
    selectedMonth: string | null;
    cell: (column: ForecastMonthColumn) => ReactNode;
}) {
    const isSummary = look === 'summary';

    return (
        <tr
            className={cn(
                look === 'group' && 'border-t',
                isSummary && 'border-t bg-muted/50',
            )}
            data-test={`forecast-row-${id}`}
        >
            <th
                scope="row"
                className={cn(
                    STICKY,
                    'py-1 pr-4 text-left font-normal',
                    look === 'child' ? 'pl-10' : 'pl-3',
                    isSummary && 'bg-muted pl-4 font-medium',
                )}
            >
                {header}
            </th>
            {months.map((column) => (
                <td
                    key={column.month}
                    className={cn(
                        'p-0.5',
                        column.status === 'current' && 'bg-muted/30',
                        column.month === selectedMonth && 'bg-muted',
                    )}
                >
                    {cell(column)}
                </td>
            ))}
        </tr>
    );
}
