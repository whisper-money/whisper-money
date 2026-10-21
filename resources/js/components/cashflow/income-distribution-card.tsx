import { index as budgetsIndex } from '@/actions/App/Http/Controllers/BudgetController';
import {
    INVESTMENTS_TONE,
    SAVINGS_TONE,
} from '@/components/cashflow/saved-invested-card';
import { signTone } from '@/components/cashflow/summary-card-parts';
import { AmountDisplay } from '@/components/ui/amount-display';
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

/** A whole-unit amount, as every figure in this section is shown. */
function Amount({ amount, currency, className }: AmountProps) {
    return (
        <AmountDisplay
            amountInCents={amount}
            currencyCode={currency}
            minimumFractionDigits={0}
            maximumFractionDigits={0}
            className={className}
        />
    );
}

interface BracketProps {
    label: string;
    amount: number;
    width: number;
    currency: string;
}

/** A labelled span over the part of the bar it adds up. */
function Bracket({ label, amount, width, currency }: BracketProps) {
    return (
        <div
            className="min-w-0 border-b-2 border-muted-foreground/40 pb-1 text-xs text-muted-foreground"
            style={{ width: `${width}%` }}
        >
            <span className="block truncate">
                {label} ·{' '}
                <Amount
                    amount={amount}
                    currency={currency}
                    className="text-xs"
                />
            </span>
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
                `${segmentLabel(segment.key)} ${segment.width.toFixed(0)}%`,
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
            <div className="flex gap-px">
                {distribution.allocatedWidth > 0 && (
                    <Bracket
                        label={__('Allocated')}
                        amount={distribution.allocated}
                        width={distribution.allocatedWidth}
                        currency={currency}
                    />
                )}
                {/* Any narrower and the bracket can't hold its label. */}
                {distribution.availableWidth >= 12 && (
                    <Bracket
                        label={__('Available')}
                        amount={distribution.available}
                        width={distribution.availableWidth}
                        currency={currency}
                    />
                )}
            </div>
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
                <DistributionLegend
                    segments={distribution.segments}
                    currency={currency}
                />
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
