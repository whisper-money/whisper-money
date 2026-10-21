import { index as budgetsIndex } from '@/actions/App/Http/Controllers/BudgetController';
import {
    INVESTMENTS_TONE,
    SAVINGS_TONE,
    signTone,
    WholeAmount,
} from '@/components/cashflow/summary-card-parts';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    type CashflowBudgetMargin,
    type CashflowSummary,
} from '@/hooks/use-cashflow-data';
import {
    type DistributionSegment,
    type DistributionSegmentKey,
    type IncomeDistribution,
    incomeDistribution,
} from '@/lib/income-distribution';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';

interface IncomeDistributionCardProps {
    summary: CashflowSummary;
    loading?: boolean;
    currency?: string;
}

// Neighbouring segments differ in lightness, not only in hue, so the bar still
// reads in grayscale or with a color vision deficiency.
const SEGMENT_TONES: Record<DistributionSegmentKey, string> = {
    fixed: 'bg-rose-800 dark:bg-rose-700',
    variable: 'bg-rose-500 dark:bg-rose-400',
    saved: SAVINGS_TONE,
    invested: INVESTMENTS_TONE,
    reserved: 'bg-amber-600 dark:bg-amber-500',
    free: 'bg-sky-300 dark:bg-sky-200',
};

function segmentLabel(key: DistributionSegmentKey): string {
    const labels: Record<DistributionSegmentKey, string> = {
        fixed: __('Fixed expenses'),
        variable: __('Variable expenses'),
        saved: __('Saved (cashflow)'),
        invested: __('Invested'),
        reserved: __('Reserved'),
        free: __('Free (cashflow)'),
    };

    return labels[key];
}

interface AmountProps {
    amount: number;
    currency: string;
    className?: string;
}

function Amount({ amount, currency, className }: AmountProps) {
    return (
        <WholeAmount
            amountInCents={amount}
            currencyCode={currency}
            className={className}
        />
    );
}

/** Below this share of the bar a slice reads as "<1%" rather than "0%". */
const SMALLEST_LABELLED_SHARE = 1;

function shareLabel(width: number): string {
    return width < SMALLEST_LABELLED_SHARE ? '<1%' : `${width.toFixed(0)}%`;
}

interface BracketLabelProps {
    label: string;
    amount: number;
    currency: string;
    amountClassName?: string;
}

function BracketLabel({
    label,
    amount,
    currency,
    amountClassName,
}: BracketLabelProps) {
    return (
        <span className="whitespace-nowrap">
            {label} ·{' '}
            <Amount
                amount={amount}
                currency={currency}
                className={cn('text-xs', amountClassName)}
            />
        </span>
    );
}

/**
 * "Allocated" and "Available" over the parts of the bar they add up. The
 * labels sit at either end so a thin Available slice still gets its own.
 */
function Brackets({
    distribution,
    currency,
}: {
    distribution: IncomeDistribution;
    currency: string;
}) {
    return (
        <div className="flex flex-col gap-1 text-xs text-muted-foreground">
            <div className="flex flex-wrap justify-between gap-x-4">
                <BracketLabel
                    label={__('Allocated')}
                    amount={distribution.allocated}
                    currency={currency}
                />
                <BracketLabel
                    label={__('Available')}
                    amount={distribution.available}
                    currency={currency}
                    amountClassName={signTone(distribution.available)}
                />
            </div>
            <div aria-hidden className="flex gap-px">
                {distribution.allocatedWidth > 0 && (
                    <div
                        className="h-1.5 rounded-t-sm border-x-2 border-t-2 border-muted-foreground/40"
                        style={{ width: `${distribution.allocatedWidth}%` }}
                    />
                )}
                {distribution.availableWidth > 0 && (
                    <div
                        className="h-1.5 rounded-t-sm border-x-2 border-t-2 border-muted-foreground/40"
                        style={{ width: `${distribution.availableWidth}%` }}
                    />
                )}
            </div>
        </div>
    );
}

function DistributionBar({
    distribution,
    currency,
}: {
    distribution: IncomeDistribution;
    currency: string;
}) {
    const visible = distribution.segments.filter(
        (segment) => segment.width > 0,
    );
    const description = visible
        .map(
            (segment) =>
                `${segmentLabel(segment.key)} ${shareLabel(segment.width)}`,
        )
        .join(', ');

    return (
        <div className="flex flex-col gap-2">
            <p className="text-sm text-muted-foreground">
                {__('Income')} ·{' '}
                <Amount
                    amount={distribution.income}
                    currency={currency}
                    className="font-medium text-foreground"
                />
            </p>
            <Brackets distribution={distribution} currency={currency} />
            <div
                role="img"
                aria-label={__('Income distribution: :shares', {
                    shares: description || __('nothing to show'),
                })}
                className="flex h-4 gap-px overflow-hidden rounded-md bg-muted"
            >
                {visible.map((segment) => (
                    <div
                        key={segment.key}
                        className={SEGMENT_TONES[segment.key]}
                        style={{ width: `${segment.width}%` }}
                    />
                ))}
            </div>
        </div>
    );
}

/** Why the bar can't show a negative Free, when it is one. */
function NegativeFreeNote({
    distribution,
}: {
    distribution: IncomeDistribution;
}) {
    if (distribution.available < 0) {
        return (
            <p className="text-xs text-muted-foreground">
                {__(
                    'More was allocated than came in, so the bar is drawn over what was allocated and Free is negative.',
                )}
            </p>
        );
    }

    if (distribution.free < 0) {
        return (
            <p className="text-xs text-muted-foreground">
                {__(
                    'Budgets still reserve more than is available, so Free is negative.',
                )}
            </p>
        );
    }

    return null;
}

function DistributionLegend({
    segments,
    currency,
}: {
    segments: DistributionSegment[];
    currency: string;
}) {
    return (
        <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            {segments.map((segment) => (
                <li key={segment.key} className="flex items-start gap-2">
                    <span
                        aria-hidden
                        className={cn(
                            'mt-1 size-2.5 shrink-0 rounded-sm',
                            SEGMENT_TONES[segment.key],
                        )}
                    />
                    <div className="min-w-0">
                        <p className="text-xs text-muted-foreground">
                            {segmentLabel(segment.key)}
                        </p>
                        <Amount
                            amount={segment.amount}
                            currency={currency}
                            className={cn(
                                'text-sm font-medium',
                                segment.amount < 0 &&
                                    'text-red-600 dark:text-red-400',
                            )}
                        />
                    </div>
                </li>
            ))}
        </ul>
    );
}

interface EquationTermProps {
    label: string;
    amount: number;
    currency: string;
    className?: string;
}

function EquationTerm({
    label,
    amount,
    currency,
    className,
}: EquationTermProps) {
    return (
        <div className="flex flex-col">
            <span className="text-xs text-muted-foreground">{label}</span>
            <Amount
                amount={amount}
                currency={currency}
                className={cn('text-lg font-semibold', className)}
            />
        </div>
    );
}

function AvailableToFree({
    distribution,
    currency,
}: {
    distribution: IncomeDistribution;
    currency: string;
}) {
    return (
        <section className="flex flex-col gap-2">
            <h3 className="text-sm font-medium">
                {__('From available to free')}
            </h3>
            <div className="flex flex-wrap items-end gap-x-4 gap-y-2 rounded-md bg-muted/60 px-4 py-3">
                <EquationTerm
                    label={__('Available')}
                    amount={distribution.available}
                    currency={currency}
                />
                <span aria-hidden className="pb-1 text-muted-foreground">
                    −
                </span>
                <EquationTerm
                    label={__('Reserved')}
                    amount={distribution.reserved}
                    currency={currency}
                />
                <span aria-hidden className="pb-1 text-muted-foreground">
                    =
                </span>
                <EquationTerm
                    label={__('Free (cashflow)')}
                    amount={distribution.free}
                    currency={currency}
                    className={signTone(distribution.free)}
                />
            </div>
        </section>
    );
}

function BudgetMarginsTable({
    budgets,
    reserved,
    currency,
}: {
    budgets: CashflowBudgetMargin[];
    reserved: number;
    currency: string;
}) {
    return (
        <>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>{__('Budget')}</TableHead>
                        <TableHead className="text-right">
                            {__('Limit')}
                        </TableHead>
                        <TableHead className="text-right">
                            {__('Spent')}
                        </TableHead>
                        <TableHead className="text-right">
                            {__('Margin')}
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {budgets.map((budget) => (
                        <TableRow key={budget.budget_period_id}>
                            <TableCell className="font-medium">
                                {budget.name}
                            </TableCell>
                            <TableCell className="text-right">
                                <Amount
                                    amount={budget.allocated}
                                    currency={currency}
                                />
                            </TableCell>
                            <TableCell className="text-right">
                                <Amount
                                    amount={budget.spent}
                                    currency={currency}
                                />
                            </TableCell>
                            <TableCell className="text-right">
                                <Amount
                                    amount={budget.margin}
                                    currency={currency}
                                    className={cn(
                                        budget.margin < 0 &&
                                            'text-red-600 dark:text-red-400',
                                    )}
                                />
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
                <TableFooter>
                    <TableRow>
                        <TableCell colSpan={3}>{__('Reserved')}</TableCell>
                        <TableCell className="text-right">
                            <Amount amount={reserved} currency={currency} />
                        </TableCell>
                    </TableRow>
                </TableFooter>
            </Table>
            <p className="text-xs text-muted-foreground">
                {__(
                    "An over-budget budget doesn't reduce the others' reserve: what it overspent already counts as an expense.",
                )}
            </p>
        </>
    );
}

function BudgetMargins({
    budgets,
    reserved,
    currency,
}: {
    budgets: CashflowBudgetMargin[];
    reserved: number;
    currency: string;
}) {
    return (
        <section className="flex flex-col gap-2">
            <h3 className="text-sm font-medium">
                {__('Variable budgets margin')}
            </h3>
            {budgets.length > 0 ? (
                <BudgetMarginsTable
                    budgets={budgets}
                    reserved={reserved}
                    currency={currency}
                />
            ) : (
                <p className="text-sm text-muted-foreground">
                    {__(
                        'No budget is running in this period, so nothing is reserved.',
                    )}{' '}
                    <Link
                        href={budgetsIndex()}
                        className="font-medium text-foreground underline underline-offset-4"
                    >
                        {__('Go to budgets')}
                    </Link>
                </p>
            )}
        </section>
    );
}

export function IncomeDistributionCard({
    summary,
    loading,
    currency = 'USD',
}: IncomeDistributionCardProps) {
    const header = (
        <CardHeader className="pb-4">
            <CardTitle className="text-base">
                {__('Income distribution')}
            </CardTitle>
            <CardDescription>
                {__('Where every unit of income went this period')}
            </CardDescription>
        </CardHeader>
    );

    if (loading) {
        return (
            <Card>
                {header}
                <CardContent>
                    <div className="h-24 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                </CardContent>
            </Card>
        );
    }

    const distribution = incomeDistribution(summary);

    return (
        <Card>
            {header}
            <CardContent className="flex flex-col gap-6">
                <DistributionBar
                    distribution={distribution}
                    currency={currency}
                />
                <div className="flex flex-col gap-3">
                    <DistributionLegend
                        segments={distribution.segments}
                        currency={currency}
                    />
                    <NegativeFreeNote distribution={distribution} />
                </div>
                <AvailableToFree
                    distribution={distribution}
                    currency={currency}
                />
                <BudgetMargins
                    budgets={summary.budgets ?? []}
                    reserved={distribution.reserved}
                    currency={currency}
                />
            </CardContent>
        </Card>
    );
}
