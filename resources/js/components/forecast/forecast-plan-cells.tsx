import { Badge } from '@/components/ui/badge';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { useLocale } from '@/hooks/use-locale';
import {
    balanceTone,
    dayOf,
    lineKindLabel,
    lineOrigin,
} from '@/lib/forecast/format';
import {
    estimateBand,
    groupCurrentTotal,
    groupMonthTotal,
    opensIntoLines,
    type PlanRow,
} from '@/lib/forecast/plan-rows';
import { type ProjectedMonth } from '@/lib/forecast/types';
import { cn } from '@/lib/utils';
import {
    type ForecastEstimate,
    type ForecastGroup,
    type ForecastLine,
    type ForecastLineKind,
} from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { type ReactNode } from 'react';

/** What every plan cell needs to know about the table it sits in. */
export interface PlanCellContext {
    today: string;
    currencyCode: string;
    alertThreshold: number | null;
    projectionByMonth: Record<string, ProjectedMonth>;
}

function useNumber(currencyCode: string): (amount: number) => string {
    const { formatAmountNumber } = useFormatAmount();

    return (amount) =>
        formatAmountNumber(amount, currencyCode, { fractionDigits: 0 });
}

export function PlanCell({
    children,
    sub,
    className,
    subClassName,
    title,
}: {
    children: ReactNode;
    sub?: ReactNode;
    className?: string;
    subClassName?: string;
    /** What hovering the figure tells: an estimate's band. */
    title?: string;
}) {
    return (
        <span
            title={title}
            className={cn(
                'flex min-h-8 w-full flex-col items-end justify-center gap-0.5 rounded-md px-2 py-0.5 text-[13px] tabular-nums',
                className,
            )}
        >
            <span>{children}</span>
            {sub !== undefined && (
                <span
                    className={cn(
                        'text-[10px] whitespace-nowrap text-muted-foreground',
                        subClassName,
                    )}
                >
                    {sub}
                </span>
            )}
        </span>
    );
}

export const EMPTY_CELL = (
    <PlanCell className="text-muted-foreground">—</PlanCell>
);

/** A month the free plan does not show: a blurred placeholder, no figure. */
export function LockedCell() {
    return (
        <span
            aria-label={__('Available with Pro')}
            className="flex min-h-8 items-center justify-end px-2 text-[13px] text-muted-foreground blur-[4px] select-none"
            data-test="forecast-locked-cell"
        >
            0.000
        </span>
    );
}

const KIND_BADGE: Record<ForecastLineKind | 'estimate', string> = {
    monthly: 'border-transparent bg-chart-1/15 text-foreground',
    bimonthly: 'border-transparent bg-chart-1/15 text-foreground',
    quarterly: 'border-transparent bg-chart-1/15 text-foreground',
    yearly: 'border-warning/20 bg-warning/10 text-warning',
    once: 'border-transparent bg-chart-3/15 text-foreground',
    transfer: 'border-transparent bg-muted text-muted-foreground',
    estimate:
        'border-dashed border-muted-foreground/50 bg-muted text-muted-foreground',
};

export function KindBadge({ kind }: { kind: ForecastLineKind | 'estimate' }) {
    return (
        <Badge
            variant="outline"
            className={cn(
                'rounded-full px-2 py-0 text-[11px]',
                KIND_BADGE[kind],
            )}
        >
            {kind === 'estimate' ? __('Estimated') : lineKindLabel(kind)}
        </Badge>
    );
}

/** A line's name: a transfer reads as an arrow to (or from) the other account. */
export function lineName(line: ForecastLine): string {
    if (line.kind !== 'transfer') {
        return line.name;
    }

    const arrow = line.direction === 'in' ? '←' : '→';

    return `${arrow} ${line.counterpartyName ?? line.name}`;
}

/** "Checking · Added by you · day 28": the origin, after the account it is on when the view shows several. */
function withAccount(origin: string, accountName?: string): string {
    return accountName ? `${accountName} · ${origin}` : origin;
}

export function LineHeader({
    line,
    name,
    accountName,
}: {
    line: ForecastLine;
    /** Shown instead of the line's own name. */
    name?: string;
    /** The account the line is on, when the view shows several. */
    accountName?: string;
}) {
    const locale = useLocale();

    return (
        <span className="flex flex-col gap-1">
            <span className="text-[13px]">{name ?? lineName(line)}</span>
            <span className="flex flex-wrap items-center gap-1.5">
                <KindBadge kind={line.kind} />
                <span className="text-[11px] text-muted-foreground">
                    {withAccount(
                        lineOrigin(
                            line.kind,
                            line.source,
                            line.anchorDate,
                            locale,
                        ),
                        accountName,
                    )}
                </span>
            </span>
        </span>
    );
}

/** Where a category's estimate comes from: "Median of 3 months", "Set by you", its budget. */
export function useEstimateBasis(
    currencyCode: string,
): (estimate: ForecastEstimate) => string {
    const number = useNumber(currencyCode);

    return (estimate) => {
        if (estimate.basis === 'adjustment') {
            return __('Set by you');
        }

        if (estimate.basis !== 'budget' || estimate.budgetAmount === null) {
            return __('Median of 3 months');
        }

        const firstPlanned = Object.values(estimate.amounts)[0];
        const concrete =
            firstPlanned === undefined
                ? 0
                : estimate.budgetAmount + firstPlanned;

        return concrete > 0
            ? __('Budget :budget − concrete :concrete', {
                  budget: number(estimate.budgetAmount),
                  concrete: number(concrete),
              })
            : __('Budget :budget', { budget: number(estimate.budgetAmount) });
    };
}

/** "Likely ± 120": how far a month of the category may land from its estimate. */
export function useBandHint(
    currencyCode: string,
): (kind: ForecastEstimate['kind'], amount: number) => string {
    const number = useNumber(currencyCode);

    return (kind, amount) =>
        __('Likely ± :amount', { amount: number(estimateBand(kind, amount)) });
}

/** A category's name, and where its estimate comes from under it. */
export function CategoryHeader({
    name,
    estimate,
    currencyCode,
    accountName,
}: {
    name: string;
    estimate: ForecastEstimate | null;
    currencyCode: string;
    /** The account the estimate is on, when the view shows several. */
    accountName?: string;
}) {
    const basis = useEstimateBasis(currencyCode);
    const hint =
        estimate === null
            ? accountName
            : withAccount(basis(estimate), accountName);

    return (
        <span className="flex min-h-8 flex-col justify-center gap-px text-left">
            <span className="text-[13px] font-semibold">{name}</span>
            {hint && (
                <span className="text-[11px] font-normal text-muted-foreground">
                    {hint}
                </span>
            )}
        </span>
    );
}

export function EstimateHeader({
    estimate,
    currencyCode,
    accountName,
}: {
    estimate: ForecastEstimate;
    currencyCode: string;
    /** The account the estimate is for, when the view shows several. */
    accountName?: string;
}) {
    const basis = useEstimateBasis(currencyCode);

    return (
        <span className="flex flex-col gap-1">
            <span className="text-[13px]">{__('Estimate')}</span>
            <span className="flex flex-wrap items-center gap-1.5">
                <KindBadge kind="estimate" />
                <span className="text-[11px] text-muted-foreground">
                    {withAccount(basis(estimate), accountName)}
                </span>
            </span>
        </span>
    );
}

/** Income reads in the success colour with its "+"; spend in the foreground with its sign. */
export function signedFigure(
    amount: number,
    format: (amount: number) => string,
    { signed = true }: { signed?: boolean } = {},
): { text: string; className: string | undefined } {
    if (signed && amount > 0) {
        return { text: `+${format(amount)}`, className: 'text-success' };
    }

    // `|| 0` keeps an untouched spend (−0) from reading "−0".
    return { text: format(amount || 0), className: undefined };
}

function deviationTone(deviation: number): string {
    if (deviation === 0) {
        return 'text-muted-foreground';
    }

    return deviation > 0 ? 'text-success' : 'text-muted-foreground';
}

/** "arrives on the 28th" while it is to come, "expected on the 1st" once overdue. */
function pendingLabel(date: string | null, today: string): string | undefined {
    if (date === null) {
        return undefined;
    }

    return date < today
        ? __('expected on the :day', { day: dayOf(date) })
        : __('arrives on the :day', { day: dayOf(date) });
}

function LineCurrentCell({
    line,
    currencyCode,
    today,
}: {
    line: ForecastLine;
    currencyCode: string;
    today: string;
}) {
    const number = useNumber(currencyCode);
    const { real, planned, status, date } = line.current;

    if (status === 'pending') {
        return (
            <PlanCell sub={pendingLabel(date, today)}>{__('pending')}</PlanCell>
        );
    }

    if (status === 'none' || real === null) {
        return EMPTY_CELL;
    }

    const deviation = real - (planned ?? real);

    return (
        <PlanCell
            sub={
                deviation === 0
                    ? __('✓ as planned')
                    : __('pl. :planned · :deviation', {
                          planned: number(planned ?? 0),
                          deviation: `${deviation > 0 ? '+' : '−'}${number(Math.abs(deviation))}`,
                      })
            }
            subClassName={deviationTone(deviation)}
        >
            {number(real)}
        </PlanCell>
    );
}

function EstimateCurrentCell({
    estimate,
    currencyCode,
    className,
}: {
    estimate: ForecastEstimate;
    currencyCode: string;
    className?: string;
}) {
    const number = useNumber(currencyCode);

    if (estimate.current === null) {
        return EMPTY_CELL;
    }

    const { spent, planned, left } = estimate.current;
    const figure = signedFigure(
        estimate.kind === 'income' ? spent : -spent,
        number,
    );

    return (
        <PlanCell
            className={cn(figure.className, className)}
            sub={__('of :planned · :left left', {
                planned: number(planned),
                left: number(left),
            })}
            subClassName={spent > planned ? 'text-foreground' : undefined}
        >
            {figure.text}
        </PlanCell>
    );
}

/**
 * A category's figure in a month: "≈ X" when an estimate makes it up,
 * income in the success colour, and on hover the band of the estimated
 * part (the lines the user added are exact).
 */
export function CategoryMonthCell({
    total,
    estimate,
    currencyCode,
}: {
    total: number | undefined;
    /** The estimate's side and its amount in the month, when one is part of the figure. */
    estimate: { kind: ForecastEstimate['kind']; amount: number | null } | null;
    currencyCode: string;
}) {
    const number = useNumber(currencyCode);
    const band = useBandHint(currencyCode);

    if (total === undefined) {
        return EMPTY_CELL;
    }

    const figure = signedFigure(total, number);

    return (
        <PlanCell
            className={cn('font-semibold', figure.className)}
            title={
                estimate?.amount == null
                    ? undefined
                    : band(estimate.kind, estimate.amount)
            }
        >
            {estimate === null ? figure.text : `≈ ${figure.text}`}
        </PlanCell>
    );
}

function GroupCell({
    group,
    month,
    current,
    currencyCode,
}: {
    group: ForecastGroup;
    month: string;
    current: boolean;
    currencyCode: string;
}) {
    const number = useNumber(currencyCode);

    if (current && group.estimate !== null && !opensIntoLines(group)) {
        return (
            <EstimateCurrentCell
                estimate={group.estimate}
                currencyCode={currencyCode}
                className="font-semibold"
            />
        );
    }

    if (current) {
        const total = groupCurrentTotal(group);
        const figure = signedFigure(total.real, number);

        return (
            <PlanCell className={cn('font-semibold', figure.className)}>
                {total.real === 0 && total.pending
                    ? __('pending')
                    : figure.text}
            </PlanCell>
        );
    }

    return (
        <CategoryMonthCell
            total={groupMonthTotal(group, month) ?? undefined}
            estimate={
                group.estimate && {
                    kind: group.estimate.kind,
                    amount: group.estimate.amounts[month] ?? null,
                }
            }
            currencyCode={currencyCode}
        />
    );
}

function SummaryCell({
    summary,
    month,
    context,
}: {
    summary: 'minimum' | 'close';
    month: string;
    context: PlanCellContext;
}) {
    const number = useNumber(context.currencyCode);
    const projected = context.projectionByMonth[month];

    if (!projected) {
        return EMPTY_CELL;
    }

    if (summary === 'close') {
        return (
            <PlanCell className="font-semibold">
                {number(projected.close)}
            </PlanCell>
        );
    }

    return (
        <PlanCell
            className={cn(
                'font-semibold',
                balanceTone(projected.min, context.alertThreshold),
            )}
            sub={__('worst case :amount', {
                amount: number(projected.pessimisticMin),
            })}
            subClassName={
                balanceTone(projected.pessimisticMin, context.alertThreshold) ||
                undefined
            }
        >
            {number(projected.min)}
        </PlanCell>
    );
}

/** A month's cell of a row that has figures (groups, lines, estimates, summaries). */
export function PlanMonthCell({
    row,
    month,
    current,
    context,
}: {
    row: PlanRow;
    month: string;
    current: boolean;
    context: PlanCellContext;
}) {
    const number = useNumber(context.currencyCode);
    const band = useBandHint(context.currencyCode);

    switch (row.type) {
        case 'group':
            return (
                <GroupCell
                    group={row.group}
                    month={month}
                    current={current}
                    currencyCode={context.currencyCode}
                />
            );
        case 'line': {
            if (current) {
                return (
                    <LineCurrentCell
                        line={row.line}
                        currencyCode={context.currencyCode}
                        today={context.today}
                    />
                );
            }

            const amount = row.line.amounts[month];

            if (amount === undefined) {
                return EMPTY_CELL;
            }

            const figure = signedFigure(amount, number, {
                signed: row.line.kind !== 'transfer',
            });

            return (
                <PlanCell className={figure.className}>{figure.text}</PlanCell>
            );
        }
        case 'estimate': {
            if (current) {
                return (
                    <EstimateCurrentCell
                        estimate={row.estimate}
                        currencyCode={context.currencyCode}
                    />
                );
            }

            const amount = row.estimate.amounts[month];

            return amount === undefined ? (
                EMPTY_CELL
            ) : (
                <PlanCell
                    className="text-muted-foreground italic"
                    title={band(row.estimate.kind, amount)}
                >
                    ≈ {number(amount)}
                </PlanCell>
            );
        }
        case 'summary':
            return (
                <SummaryCell
                    summary={row.summary}
                    month={month}
                    context={context}
                />
            );
        default:
            return null;
    }
}
