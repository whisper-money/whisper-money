import {
    useForecastEditor,
    type ForecastEditTarget,
} from '@/components/forecast/forecast-editor';
import {
    EMPTY_CELL,
    EstimateHeader,
    LineHeader,
    LockedCell,
    PlanCell,
    PlanMonthCell,
    type PlanCellContext,
} from '@/components/forecast/forecast-plan-cells';
import {
    AddLineRow,
    concreteHint,
    EditableCell,
    groupName,
    MonthHeader,
    PlanRowShell,
    PlanTableCard,
    SectionHeading,
} from '@/components/forecast/forecast-plan-table';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { useLocale } from '@/hooks/use-locale';
import {
    accountEstimateGroup,
    allGroupIds,
    buildAllPlanRows,
    shortAccountCount,
    type AllPlanRow,
} from '@/lib/forecast/all-accounts';
import { balanceTone, isShort, monthLongName } from '@/lib/forecast/format';
import { cn } from '@/lib/utils';
import {
    type ForecastAccountForecast,
    type ForecastAllData,
    type ForecastLine,
    type ForecastMonthColumn,
} from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { ChevronRightIcon } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';

interface ForecastAllPlanTableProps {
    forecast: ForecastAllData;
    selectedMonth: string | null;
    /** Next to "Expand all": the link to the review, when there is something new. */
    headerAction?: ReactNode;
}

type Section = Extract<AllPlanRow, { type: 'section' }>['section'];

function sectionLabel(section: Section): { name: string; hint: string } {
    switch (section) {
        case 'income':
            return { name: __('Income'), hint: '' };
        case 'expenses':
            return {
                name: __('Expenses'),
                hint: __('your main categories, adding up every account'),
            };
        case 'transfers':
            return {
                name: __('Transfers between your accounts'),
                hint: __('they move money; your total does not change'),
            };
        case 'closes':
            return { name: __('Closing balance by account'), hint: '' };
    }
}

function useNumber(): (amount: number, currencyCode: string) => string {
    const { formatAmountNumber } = useFormatAmount();

    return (amount, currencyCode) =>
        formatAmountNumber(amount, currencyCode, { fractionDigits: 0 });
}

/** Whether an account's close row also says its lowest point: when it has a minimum, or runs short. */
function showsMinimum(account: ForecastAccountForecast): boolean {
    return (
        account.minimumBalance !== null ||
        account.projection.some((month) =>
            isShort(month.pessimisticMin, account.alertThreshold),
        )
    );
}

function TitleWithHint({ name, hint }: { name: string; hint?: string }) {
    return (
        <span className="flex flex-col gap-px">
            <span>{name}</span>
            {hint && (
                <span className="text-[11px] font-normal text-muted-foreground">
                    {hint}
                </span>
            )}
        </span>
    );
}

interface TableContext {
    forecast: ForecastAllData;
    locale: string;
    accountName: (id: string | null | undefined) => string;
    accountCurrency: (id: string) => string;
    cellContext: (accountId: string) => PlanCellContext;
}

function transferName(line: ForecastLine, context: TableContext): string {
    const from =
        line.counterpartyName ?? context.accountName(line.definition.accountId);
    const to = context.accountName(line.definition.destinationAccountId);

    return `${from} → ${to || line.name}`;
}

function RowHeader({
    row,
    context,
    expanded,
    onToggle,
}: {
    row: Exclude<AllPlanRow, { type: 'section' } | { type: 'add' }>;
    context: TableContext;
    expanded: boolean;
    onToggle: () => void;
}) {
    const { formatAmount } = useFormatAmount();
    const format = (amount: number, currencyCode: string) =>
        formatAmount(amount, currencyCode, { fractionDigits: 0 });

    switch (row.type) {
        case 'group':
            return (
                <button
                    type="button"
                    onClick={onToggle}
                    aria-expanded={expanded}
                    className="flex min-h-8 items-center gap-2 text-left text-[13px] font-semibold"
                >
                    <ChevronRightIcon
                        aria-hidden="true"
                        className={cn(
                            'size-3.5 text-muted-foreground transition-transform',
                            expanded && 'rotate-90',
                        )}
                    />
                    <span>{groupName(row.group.name)}</span>
                    <span className="text-[11px] font-normal text-muted-foreground">
                        {concreteHint(
                            row.group.items.length,
                            row.group.estimates.length > 0,
                        )}
                    </span>
                </button>
            );
        case 'line':
            return (
                <LineHeader
                    line={row.line}
                    currencyCode={context.accountCurrency(row.line.accountId)}
                    name={
                        row.line.kind === 'transfer'
                            ? transferName(row.line, context)
                            : undefined
                    }
                    accountName={
                        row.line.kind === 'transfer'
                            ? undefined
                            : context.accountName(row.line.accountId)
                    }
                />
            );
        case 'estimate':
            return (
                <EstimateHeader
                    estimate={row.estimate}
                    currencyCode={context.accountCurrency(
                        row.estimate.accountId,
                    )}
                    accountName={context.accountName(row.estimate.accountId)}
                />
            );
        case 'growth':
            return (
                <TitleWithHint
                    name={__('Estimated growth')}
                    hint={context.forecast.accountForecasts
                        .filter((account) => (account.annualRateBps ?? 0) > 0)
                        .map((account) =>
                            __(':account · +:rate% a year', {
                                account: account.name,
                                rate: (account.annualRateBps ?? 0) / 100,
                            }),
                        )
                        .join(' · ')}
                />
            );
        case 'effect':
            return <TitleWithHint name={__('Effect on the total')} />;
        case 'account':
            return (
                <TitleWithHint
                    name={row.account.name}
                    hint={
                        row.account.minimumBalance === null
                            ? undefined
                            : __('Wanted minimum :amount', {
                                  amount: format(
                                      row.account.minimumBalance,
                                      row.account.currencyCode,
                                  ),
                              })
                    }
                />
            );
        case 'total':
            return (
                <span className="font-semibold">
                    <TitleWithHint
                        name={__('Total')}
                        hint={totalHint(context, format)}
                    />
                </span>
            );
    }
}

/** "likely ± 400 € a month": the band of the first whole month. */
function totalHint(
    context: TableContext,
    format: (amount: number, currencyCode: string) => string,
): string | undefined {
    const band = context.forecast.projection[1]?.band ?? 0;

    return band === 0
        ? undefined
        : __('likely ± :amount a month', {
              amount: format(band, context.forecast.currencyCode),
          });
}

function GroupCell({
    row,
    month,
    current,
    currencyCode,
}: {
    row: Extract<AllPlanRow, { type: 'group' }>;
    month: string;
    current: boolean;
    currencyCode: string;
}) {
    const number = useNumber();

    if (current) {
        const { real, pending } = row.group.current;

        return (
            <PlanCell className="font-semibold">
                {real === 0 && pending
                    ? __('pending')
                    : number(real, currencyCode)}
            </PlanCell>
        );
    }

    const total = row.group.totals[month];

    if (total === undefined) {
        return EMPTY_CELL;
    }

    return (
        <PlanCell className="font-semibold">
            {row.group.estimates.length > 0
                ? `≈ ${number(total, currencyCode)}`
                : number(total, currencyCode)}
        </PlanCell>
    );
}

function AccountCloseCell({
    account,
    month,
}: {
    account: ForecastAccountForecast;
    month: string;
}) {
    const number = useNumber();
    const projected = account.projection.find(
        (candidate) => candidate.month === month,
    );

    if (projected === undefined) {
        return EMPTY_CELL;
    }

    if (!showsMinimum(account)) {
        return (
            <PlanCell>{number(projected.close, account.currencyCode)}</PlanCell>
        );
    }

    const tone = isShort(projected.pessimisticMin, account.alertThreshold)
        ? balanceTone(projected.pessimisticMin, account.alertThreshold) ||
          'text-warning'
        : '';

    return (
        <PlanCell
            sub={__('min. :amount', {
                amount: number(projected.min, account.currencyCode),
            })}
            subClassName={cn(
                isShort(projected.min, account.alertThreshold)
                    ? balanceTone(projected.min, account.alertThreshold)
                    : tone,
                (tone || isShort(projected.min, account.alertThreshold)) &&
                    'font-semibold',
            )}
        >
            {number(projected.close, account.currencyCode)}
        </PlanCell>
    );
}

function MonthFigure({
    row,
    column,
    context,
}: {
    row: Exclude<AllPlanRow, { type: 'section' } | { type: 'add' }>;
    column: ForecastMonthColumn;
    context: TableContext;
}) {
    const number = useNumber();
    const { forecast } = context;
    const current = column.status === 'current';
    const month = column.month;

    switch (row.type) {
        case 'group':
            return (
                <GroupCell
                    row={row}
                    month={month}
                    current={current}
                    currencyCode={forecast.currencyCode}
                />
            );
        case 'line':
            return (
                <span
                    className={cn(
                        'flex w-full',
                        row.line.kind === 'transfer' && 'text-muted-foreground',
                    )}
                >
                    <PlanMonthCell
                        row={{ type: 'line', id: row.id, line: row.line }}
                        month={month}
                        current={current}
                        context={context.cellContext(row.line.accountId)}
                    />
                </span>
            );
        case 'estimate':
            return (
                <PlanMonthCell
                    row={{
                        type: 'estimate',
                        id: row.id,
                        estimate: row.estimate,
                        group: accountEstimateGroup(row.group, row.estimate),
                    }}
                    month={month}
                    current={current}
                    context={context.cellContext(row.estimate.accountId)}
                />
            );
        case 'growth': {
            const growth = forecast.plan.growth[month] ?? 0;

            return growth === 0 ? (
                EMPTY_CELL
            ) : (
                <PlanCell className="text-success">
                    +{number(growth, forecast.currencyCode)}
                </PlanCell>
            );
        }
        case 'effect':
            return (
                <PlanCell className="text-muted-foreground">
                    {number(
                        forecast.plan.transferEffect[month] ?? 0,
                        forecast.currencyCode,
                    )}
                </PlanCell>
            );
        case 'account':
            return <AccountCloseCell account={row.account} month={month} />;
        case 'total': {
            const projected = forecast.projection.find(
                (candidate) => candidate.month === month,
            );

            return projected === undefined ? (
                EMPTY_CELL
            ) : (
                <PlanCell className="font-semibold">
                    {number(projected.close, forecast.currencyCode)}
                </PlanCell>
            );
        }
    }
}

/** What a cell opens: a line or an estimated rest of its own account, in a month that can change. */
function editTarget(
    row: AllPlanRow,
    column: ForecastMonthColumn,
): ForecastEditTarget | null {
    if (column.status === 'locked') {
        return null;
    }

    if (row.type === 'line') {
        const editable =
            row.line.occurrenceDates[column.month] !== undefined &&
            (column.status !== 'current' ||
                row.line.current.status === 'pending');

        return editable
            ? { kind: 'line', line: row.line, month: column.month }
            : null;
    }

    if (row.type === 'estimate') {
        return {
            kind: 'estimate',
            group: accountEstimateGroup(row.group, row.estimate),
            month: column.month,
            accountId: row.estimate.accountId,
        };
    }

    return null;
}

function rowLabel(row: AllPlanRow, context: TableContext): string {
    if (row.type === 'line') {
        return row.line.kind === 'transfer'
            ? transferName(row.line, context)
            : row.line.name;
    }

    return row.type === 'estimate'
        ? __('Estimated rest of :group', { group: groupName(row.group.name) })
        : '';
}

function MonthCell({
    row,
    column,
    context,
}: {
    row: Exclude<AllPlanRow, { type: 'section' } | { type: 'add' }>;
    column: ForecastMonthColumn;
    context: TableContext;
}) {
    const editor = useForecastEditor();

    if (column.status === 'locked') {
        return <LockedCell />;
    }

    const content = <MonthFigure row={row} column={column} context={context} />;
    const target = editTarget(row, column);

    if (target === null || editor === null) {
        return content;
    }

    return (
        <EditableCell
            label={`${rowLabel(row, context)} · ${monthLongName(column.month, context.locale)}`}
            onOpen={() => editor.open(target)}
            testId={`forecast-cell-${row.id}-${column.month}`}
        >
            {content}
        </EditableCell>
    );
}

function look(
    row: Exclude<AllPlanRow, { type: 'section' } | { type: 'add' }>,
): 'group' | 'child' | 'summary' | 'plain' {
    switch (row.type) {
        case 'group':
            return 'group';
        case 'line':
        case 'estimate':
            return 'child';
        default:
            return 'summary';
    }
}

/**
 * "Your plan · all accounts": the user's parent categories adding up every
 * account (each line and estimated rest says which account it is on), the
 * estimated growth, the transfers between the accounts, which leave the
 * total as it is, and how each account and the total close every month.
 * Tapping a figure edits it on its own account.
 */
export function ForecastAllPlanTable({
    forecast,
    selectedMonth,
    headerAction,
}: ForecastAllPlanTableProps) {
    const locale = useLocale();
    const [open, setOpen] = useState<Record<string, boolean>>({});
    const groupIds = useMemo(() => allGroupIds(forecast), [forecast]);
    const rows = useMemo(
        () => buildAllPlanRows(forecast, (id) => open[id] === true),
        [forecast, open],
    );
    const context: TableContext = useMemo(() => {
        const accounts = new Map(
            forecast.accountForecasts.map((account) => [account.id, account]),
        );

        return {
            forecast,
            locale,
            accountName: (id) => (id ? (accounts.get(id)?.name ?? '') : ''),
            accountCurrency: (id) =>
                accounts.get(id)?.currencyCode ?? forecast.currencyCode,
            cellContext: (accountId) => ({
                today: forecast.today,
                currencyCode:
                    accounts.get(accountId)?.currencyCode ??
                    forecast.currencyCode,
                alertThreshold: null,
                projectionByMonth: {},
            }),
        };
    }, [forecast, locale]);
    const allExpanded =
        groupIds.length > 0 && groupIds.every((id) => open[id] === true);
    const colSpan = forecast.months.length + 1;

    return (
        <PlanTableCard
            title={__('Your plan · all accounts')}
            description={__(
                'Your categories adding up every account; each concrete line says which account it is on. Below, how each account closes.',
            )}
            allExpanded={allExpanded}
            headerAction={headerAction}
            onToggleAll={() =>
                setOpen(
                    allExpanded
                        ? {}
                        : Object.fromEntries(groupIds.map((id) => [id, true])),
                )
            }
            columns={forecast.months.map((column) => {
                const short =
                    column.status === 'future'
                        ? shortAccountCount(forecast, column.month)
                        : 0;

                return (
                    <MonthHeader
                        key={column.month}
                        column={column}
                        selected={column.month === selectedMonth}
                        locale={locale}
                        warning={
                            short === 0
                                ? null
                                : short === 1
                                  ? __('⚠ 1 account')
                                  : __('⚠ :count accounts', { count: short })
                        }
                    />
                );
            })}
        >
            {rows.map((row) => {
                if (row.type === 'section') {
                    return (
                        <SectionHeading
                            key={row.id}
                            {...sectionLabel(row.section)}
                            colSpan={colSpan}
                        />
                    );
                }

                if (row.type === 'add') {
                    return (
                        <AddLineRow
                            key={row.id}
                            section={row.section}
                            group={row.group}
                            colSpan={colSpan}
                        />
                    );
                }

                return (
                    <PlanRowShell
                        key={row.id}
                        id={row.id}
                        look={look(row)}
                        header={
                            <RowHeader
                                row={row}
                                context={context}
                                expanded={open[row.id] === true}
                                onToggle={() =>
                                    setOpen((current) => ({
                                        ...current,
                                        [row.id]: !current[row.id],
                                    }))
                                }
                            />
                        }
                        months={forecast.months}
                        selectedMonth={selectedMonth}
                        cell={(column) => (
                            <MonthCell
                                row={row}
                                column={column}
                                context={context}
                            />
                        )}
                    />
                );
            })}
        </PlanTableCard>
    );
}
