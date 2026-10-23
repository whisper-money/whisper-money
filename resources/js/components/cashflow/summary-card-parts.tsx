import { AmountDisplay } from '@/components/ui/amount-display';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CashflowSummary } from '@/hooks/use-cashflow-data';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';
import { TrendingDown, TrendingUp } from 'lucide-react';
import { type ComponentProps, type ReactNode } from 'react';

// One color per side of what was contributed, shared by every place that draws
// the split so savings and investments read the same across the page.
export const SAVINGS_TONE = 'bg-green-300';
export const INVESTMENTS_TONE = 'bg-green-700 dark:bg-green-600';

type WholeAmountProps = Omit<
    ComponentProps<typeof AmountDisplay>,
    'minimumFractionDigits' | 'maximumFractionDigits'
>;

/** An amount in whole units, as the cashflow summary shows every figure. */
export function WholeAmount(props: WholeAmountProps) {
    return (
        <AmountDisplay
            {...props}
            minimumFractionDigits={0}
            maximumFractionDigits={0}
        />
    );
}

function trendColor(isFavorable: boolean): string {
    return isFavorable
        ? 'text-green-600 dark:text-green-400'
        : 'text-red-600 dark:text-red-400';
}

/**
 * `part` as a percentage of `whole`, or 0 when there is no whole to read it
 * against.
 */
export function shareOf(part: number, whole: number): number {
    if (whole <= 0) {
        return 0;
    }

    return (part / whole) * 100;
}

interface PercentageComparisonProps {
    /** Percentage points between this period and the previous one. */
    diff: number;
}

export function PercentageComparison({ diff }: PercentageComparisonProps) {
    const isIncrease = diff >= 0;
    const Icon = isIncrease ? TrendingUp : TrendingDown;

    return (
        <div className="mt-2 flex items-center gap-1 text-sm">
            <Icon className={cn('size-4', trendColor(isIncrease))} />
            <span>
                {isIncrease ? '+' : ''}
                {diff.toFixed(1)}%
            </span>
            <span className="text-muted-foreground">
                {__('vs last period')}
            </span>
        </div>
    );
}

interface AmountComparisonProps {
    diff: number;
    currency: string;
    /** Whether the change is good news; defaults to "it went up". */
    isFavorable?: boolean;
}

export function AmountComparison({
    diff,
    currency,
    isFavorable,
}: AmountComparisonProps) {
    const isIncrease = diff >= 0;
    const Icon = isIncrease ? TrendingUp : TrendingDown;

    return (
        <div className="mt-1 flex flex-wrap items-center gap-x-1 text-xs">
            <Icon
                className={cn('size-3', trendColor(isFavorable ?? isIncrease))}
            />
            <span className="whitespace-nowrap">
                {isIncrease ? '+' : ''}
                <WholeAmount
                    amountInCents={diff}
                    currencyCode={currency}
                    className="text-xs"
                    highlightPositive
                />
            </span>
            <span className="whitespace-nowrap text-muted-foreground">
                {__('vs last period')}
            </span>
        </div>
    );
}

/** Green when the figure is not negative, red when it is. */
export function signTone(amount: number): string {
    return trendColor(amount >= 0);
}

interface PeriodTrendProps {
    rateDiff: number;
    amountDiff: number;
    currency: string;
}

/** The headline's change against the previous period, in points and amount. */
export function PeriodTrend({
    rateDiff,
    amountDiff,
    currency,
}: PeriodTrendProps) {
    return (
        <>
            <PercentageComparison diff={rateDiff} />
            <AmountComparison diff={amountDiff} currency={currency} />
        </>
    );
}

/** The card's placeholder while its figures load. */
export function SummaryCardSkeleton({
    title,
    className,
}: {
    title: string;
    className?: string;
}) {
    return (
        <Card className={className}>
            <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium">{title}</CardTitle>
            </CardHeader>
            <CardContent>
                <div className="h-12 w-32 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
            </CardContent>
        </Card>
    );
}

interface SummaryHeadlineProps {
    amount: number;
    currency: string;
    /** The percentage written next to the amount. */
    rate: number;
    amountClassName: string;
    rateClassName?: string;
}

/** The card's big amount with its percentage beside it. */
export function SummaryHeadline({
    amount,
    currency,
    rate,
    amountClassName,
    rateClassName = 'text-muted-foreground',
}: SummaryHeadlineProps) {
    return (
        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <WholeAmount
                amountInCents={amount}
                currencyCode={currency}
                size="4xl"
                weight="bold"
                className={amountClassName}
                highlightPositive
            />
            <span
                className={cn(
                    'text-lg font-medium tabular-nums',
                    rateClassName,
                )}
            >
                {rate.toFixed(1)}%
            </span>
        </div>
    );
}

interface SummaryFigureProps {
    label: string;
    /** Sits at the end of the label's row, such as a share pill. */
    badge?: ReactNode;
    amount: number;
    currency: string;
    /** Omitted when there is no previous period to compare against. */
    comparison?: { diff: number; isFavorable?: boolean } | null;
    highlightPositive?: boolean;
    caption?: string;
}

/** One of the figures in the grid at the bottom of a summary card. */
export function SummaryFigure({
    label,
    badge,
    amount,
    currency,
    comparison,
    highlightPositive = false,
    caption,
}: SummaryFigureProps) {
    return (
        <div>
            {badge ? (
                <div className="flex items-center justify-between gap-2">
                    <p className="text-xs text-muted-foreground">{label}</p>
                    {badge}
                </div>
            ) : (
                <p className="text-xs text-muted-foreground">{label}</p>
            )}
            <WholeAmount
                amountInCents={amount}
                currencyCode={currency}
                weight="medium"
                highlightPositive={highlightPositive}
            />
            {caption && (
                <p className="text-xs text-muted-foreground">{caption}</p>
            )}
            {comparison && (
                <AmountComparison
                    diff={comparison.diff}
                    isFavorable={comparison.isFavorable}
                    currency={currency}
                />
            )}
        </div>
    );
}

interface IncomeFigureProps {
    current: CashflowSummary;
    previous: CashflowSummary;
    currency: string;
    hasPreviousData: boolean;
}

/** The period's income, as the cards that start from it show it. */
export function IncomeFigure({
    current,
    previous,
    currency,
    hasPreviousData,
}: IncomeFigureProps) {
    return (
        <SummaryFigure
            label={__('Income')}
            amount={current.income}
            currency={currency}
            highlightPositive
            comparison={
                hasPreviousData
                    ? { diff: current.income - previous.income }
                    : null
            }
        />
    );
}
