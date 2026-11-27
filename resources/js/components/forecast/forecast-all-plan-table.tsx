import {
    useForecastEditor,
    type ForecastEditTarget,
} from '@/components/forecast/forecast-editor';
import {
    CategoryHeader,
    CategoryMonthCell,
    EMPTY_CELL,
    EstimateHeader,
    LineHeader,
    LockedCell,
    PlanCell,
    PlanMonthCell,
    signedFigure,
    type PlanCellContext,
} from '@/components/forecast/forecast-plan-cells';
import {
    CategoryToggle,
    EditableCell,
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
    opensAllAccounts,
    shortAccountCount,
    soleEstimate,
    type AllPlanRow,
} from '@/lib/forecast/all-accounts';
import { estimateKind } from '@/lib/forecast/edits';
import {
    balanceTone,
    isShort,
    monthLongName,
    trendLabel,
} from '@/lib/forecast/format';
import { groupName } from '@/lib/forecast/plan-rows';
import { cn } from '@/lib/utils';
import {
    type ForecastAccountForecast,
    type ForecastAllData,
    type ForecastLine,
    type ForecastMonthColumn,
} from '@/types/forecast';
import { __ } from '@/utils/i18n';
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
        (account.minimumBalance !== null && account.alertThreshold !== null) ||
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
    row: Exclude<AllPlanRow, { type: 'section' }>;
    context: TableContext;
    expanded: boolean;
    onToggle: () => void;
}) {
    const { formatAmount } = useFormatAmount();
    const format = (amount: number, currencyCode: string) =>
        formatAmount(amount, currencyCode, { fractionDigits: 0 });

    switch (row.type) {
        case 'group': {
            const sole = soleEstimate(row.group);

            return (
                <CategoryToggle
                    expanded={opensAllAccounts(row.group) ? expanded : null}
                    onToggle={onToggle}
                >
                    <CategoryHeader
                        name={groupName(row.group)}
                        estimate={sole}
                        currencyCode={context.accountCurrency(
                            sole?.accountId ?? '',
                        )}
                        accountName={
                            sole === null
                                ? undefined
                                : context.accountName(sole.accountId)
                        }
                    />
                </CategoryToggle>
            );
        }
        case 'line':
            return (
                <LineHeader
                    line={row.line}
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
                        .filter(
                            (account) =>
                                (account.annualRateBps ?? 0) > 0 &&
                                account.trend === null,
                        )
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
                    hint={accountRowHint(row.account, format)}
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

/** What an account's closing row says under its name: projected only, or its wanted minimum, then its balance trend. */
function accountRowHint(
    account: ForecastAccountForecast,
    format: (amount: number, currencyCode: string) => string,
): string | undefined {
    const trend = trendLabel(account.trend, (amount) =>
        format(amount, account.currencyCode),
    );
    const parts = [accountRowState(account, format), trend].filter(
        (part) => part !== null,
    );

    return parts.length === 0 ? undefined : parts.join(' · ');
}

function accountRowState(
    account: ForecastAccountForecast,
    format: (amount: number, currencyCode: string) => string,
): string | null {
    if (account.projectionOnly) {
        return __('Projection only, never warns');
    }

    return account.minimumBalance === null
        ? null
        : __('Wanted minimum :amount', {
              amount: format(account.minimumBalance, account.currencyCode),
          });
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
        const figure = signedFigure(real, (amount) =>
            number(amount, currencyCode),
        );

        return (
            <PlanCell className={cn('font-semibold', figure.className)}>
                {real === 0 && pending ? __('pending') : figure.text}
            </PlanCell>
        );
    }

    return (
        <CategoryMonthCell
            total={row.group.totals[month]}
            estimate={
                row.group.estimates.length === 0
                    ? null
                    : {
                          kind: estimateKind(row.group),
                          // In the user's currency only when the figure is that one estimate.
                          amount: opensAllAccounts(row.group)
                              ? null
                              : (row.group.totals[month] ?? null),
                      }
            }
            currencyCode={currencyCode}
        />
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
    row: Exclude<AllPlanRow, { type: 'section' }>;
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
                <PlanCell
                    className={
                        growth > 0 ? 'text-success' : 'text-muted-foreground'
                    }
                >
                    {growth > 0 ? '+' : ''}
                    {number(growth, forecast.currencyCode)}
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

/** What a cell opens: a line, or a category's estimate on its own account, in a month that can change. */
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

    const estimate = row.type === 'group' ? soleEstimate(row.group) : null;
    const edited = row.type === 'estimate' ? row.estimate : estimate;

    if (edited === null || (row.type !== 'estimate' && row.type !== 'group')) {
        return null;
    }

    return {
        kind: 'estimate',
        group: accountEstimateGroup(row.group, edited),
        month: column.month,
        accountId: edited.accountId,
    };
}

function rowLabel(row: AllPlanRow, context: TableContext): string {
    if (row.type === 'line') {
        return row.line.kind === 'transfer'
            ? transferName(row.line, context)
            : row.line.name;
    }

    return row.type === 'estimate' || row.type === 'group'
        ? groupName(row.group)
        : '';
}

function MonthCell({
    row,
    column,
    context,
}: {
    row: Exclude<AllPlanRow, { type: 'section' }>;
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
    row: Exclude<AllPlanRow, { type: 'section' }>,
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
 * account (each line and estimate says which account it is on), the
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
                'Your categories adding up every account, from your last 3 months; tap a figure to change it on its account. Below, how each account closes.',
            )}
            allExpanded={allExpanded}
            headerAction={headerAction}
            onToggleAll={
                groupIds.length === 0
                    ? null
                    : () =>
                          setOpen(
                              allExpanded
                                  ? {}
                                  : Object.fromEntries(
                                        groupIds.map((id) => [id, true]),
                                    ),
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
