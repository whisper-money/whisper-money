import { useForecastEditor } from '@/components/forecast/forecast-editor';
import { Badge } from '@/components/ui/badge';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { useLocale } from '@/hooks/use-locale';
import {
    balanceTone,
    dayOf,
    lineKindLabel,
    lineOrigin,
    monthLongName,
} from '@/lib/forecast/format';
import {
    groupCurrentTotal,
    groupMonthTotal,
    type PlanRow,
} from '@/lib/forecast/plan-rows';
import { type ProjectedMonth } from '@/lib/forecast/types';
import { cn } from '@/lib/utils';
import {
    type ForecastEstimate,
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
}: {
    children: ReactNode;
    sub?: ReactNode;
    className?: string;
    subClassName?: string;
}) {
    return (
        <span
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

/** "Tu alquiler ahora es de 950 €. ¿Actualizar desde diciembre?", accepted in one tap. */
function DriftSuggestion({
    line,
    currencyCode,
}: {
    line: ForecastLine;
    currencyCode: string;
}) {
    const locale = useLocale();
    const editor = useForecastEditor();
    const { formatAmount } = useFormatAmount();

    if (line.drift === null || editor === null) {
        return null;
    }

    return (
        <button
            type="button"
            className="max-w-[230px] text-left text-[11px] text-warning underline-offset-4 hover:underline"
            onClick={() => editor.acceptDrift(line)}
            data-test={`forecast-drift-${line.id}`}
        >
            {__('Your :name is now :amount. Update from :month?', {
                name: line.name,
                amount: formatAmount(
                    Math.abs(line.drift.amount),
                    currencyCode,
                    {
                        fractionDigits: 0,
                    },
                ),
                month: monthLongName(line.drift.from.slice(0, 7), locale),
            })}
        </button>
    );
}

export function LineHeader({
    line,
    currencyCode,
}: {
    line: ForecastLine;
    currencyCode: string;
}) {
    const locale = useLocale();

    return (
        <span className="flex flex-col gap-1">
            <span className="text-[13px]">{lineName(line)}</span>
            <span className="flex flex-wrap items-center gap-1.5">
                <KindBadge kind={line.kind} />
                <span className="text-[11px] text-muted-foreground">
                    {lineOrigin(
                        line.kind,
                        line.source,
                        line.anchorDate,
                        locale,
                    )}
                </span>
            </span>
            <DriftSuggestion line={line} currencyCode={currencyCode} />
        </span>
    );
}

export function EstimateHeader({
    estimate,
    currencyCode,
}: {
    estimate: ForecastEstimate;
    currencyCode: string;
}) {
    const number = useNumber(currencyCode);
    const firstPlanned = Object.values(estimate.amounts)[0];
    let basis = __('Median of 3 months');

    if (estimate.basis === 'adjustment') {
        basis = __('Set by you');
    } else if (estimate.basis === 'budget' && estimate.budgetAmount !== null) {
        const concrete =
            firstPlanned === undefined
                ? 0
                : estimate.budgetAmount + firstPlanned;
        basis =
            concrete > 0
                ? __('Budget :budget − concrete :concrete', {
                      budget: number(estimate.budgetAmount),
                      concrete: number(concrete),
                  })
                : __('Budget :budget', {
                      budget: number(estimate.budgetAmount),
                  });
    }

    return (
        <span className="flex flex-col gap-1">
            <span className="text-[13px]">{__('Estimated rest')}</span>
            <span className="flex flex-wrap items-center gap-1.5">
                <KindBadge kind="estimate" />
                <span className="text-[11px] text-muted-foreground">
                    {basis}
                </span>
            </span>
        </span>
    );
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
}: {
    estimate: ForecastEstimate;
    currencyCode: string;
}) {
    const number = useNumber(currencyCode);

    if (estimate.current === null) {
        return EMPTY_CELL;
    }

    const { spent, planned, left } = estimate.current;

    return (
        <PlanCell
            sub={__('of :planned · :left left', {
                planned: number(planned),
                left: number(left),
            })}
            subClassName={spent > planned ? 'text-foreground' : undefined}
        >
            {number(-spent)}
        </PlanCell>
    );
}

function GroupCell({
    row,
    month,
    current,
    currencyCode,
}: {
    row: Extract<PlanRow, { type: 'group' }>;
    month: string;
    current: boolean;
    currencyCode: string;
}) {
    const number = useNumber(currencyCode);

    if (current) {
        const total = groupCurrentTotal(row.group);

        return (
            <PlanCell className="font-semibold">
                {total.real === 0 && total.pending
                    ? __('pending')
                    : number(total.real)}
            </PlanCell>
        );
    }

    const total = groupMonthTotal(row.group, month);

    if (total === null) {
        return EMPTY_CELL;
    }

    return (
        <PlanCell className="font-semibold">
            {row.group.estimate ? `≈ ${number(total)}` : number(total)}
        </PlanCell>
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

    switch (row.type) {
        case 'group':
            return (
                <GroupCell
                    row={row}
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

            return amount === undefined ? (
                EMPTY_CELL
            ) : (
                <PlanCell>{number(amount)}</PlanCell>
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
                <PlanCell className="text-muted-foreground italic">
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
