import { AmountDisplay } from '@/components/ui/amount-display';
import { Skeleton } from '@/components/ui/skeleton';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import {
    type TransactionFilterSummary as Summary,
    type TransactionFilters,
} from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { TransactionAnalysisDrawer } from './transaction-analysis-drawer';

interface TransactionFilterSummaryProps {
    /** Undefined while the server is still adding it up. */
    summary: Summary | undefined;
    filters: TransactionFilters;
    /** The active filters in a few words, for the phone's caption. */
    filterDescription: string[];
}

/** Bars the phone's analysis row draws; the desktop button draws the whole trend. */
const PHONE_TREND_BARS = 5;

/**
 * What a filtered list adds up to (how many rows, their total and average)
 * and the way into the analysis of those same filters. One card of four cells
 * on desktop; a caption, the total and the average over a full-width analysis
 * row on a phone.
 */
export function TransactionFilterSummary({
    summary,
    filters,
    filterDescription,
}: TransactionFilterSummaryProps) {
    const isMobile = useIsMobile();
    const [analysisOpen, setAnalysisOpen] = useState(false);

    if (summary === undefined) {
        return <TransactionFilterSummarySkeleton isMobile={isMobile} />;
    }

    const openAnalysis = () => setAnalysisOpen(true);

    return (
        <>
            {isMobile ? (
                <PhoneSummary
                    summary={summary}
                    filterDescription={filterDescription}
                    onOpenAnalysis={openAnalysis}
                />
            ) : (
                <DesktopSummary
                    summary={summary}
                    onOpenAnalysis={openAnalysis}
                />
            )}

            <TransactionAnalysisDrawer
                open={analysisOpen}
                onOpenChange={setAnalysisOpen}
                filters={filters}
            />
        </>
    );
}

interface SummaryLayoutProps {
    summary: Summary;
    onOpenAnalysis: () => void;
}

function DesktopSummary({ summary, onOpenAnalysis }: SummaryLayoutProps) {
    return (
        <section
            aria-label={__('Filter summary')}
            className="grid grid-cols-[repeat(3,minmax(0,1fr))_minmax(0,1.25fr)] overflow-hidden rounded-xl border bg-card"
        >
            <SummaryCell label={__('Transactions')}>
                {summary.count}
            </SummaryCell>
            <SummaryCell label={totalLabel(summary.kind)}>
                <Amount value={summary.total} currency={summary.currency} />
            </SummaryCell>
            <SummaryCell label={__('Average per transaction')}>
                <Amount value={summary.average} currency={summary.currency} />
            </SummaryCell>
            <button
                type="button"
                onClick={onOpenAnalysis}
                className="flex items-center gap-3.5 bg-muted px-4 py-3 text-left transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
            >
                <TrendBars
                    trend={summary.trend}
                    className="h-[34px] w-16 gap-[3px] [&>span]:rounded-t-[2px]"
                />
                <span className="flex min-w-0 flex-col gap-px">
                    <span className="font-semibold">{__('View analysis')}</span>
                    <span className="truncate text-xs text-muted-foreground">
                        {__('6-month trend and breakdown')}
                    </span>
                </span>
                <ChevronRight aria-hidden className="ml-auto size-4 shrink-0" />
            </button>
        </section>
    );
}

function SummaryCell({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex min-w-0 flex-col gap-0.5 border-r px-[18px] py-3.5">
            <span className="text-xs text-muted-foreground">{label}</span>
            <span className="truncate text-xl font-semibold">{children}</span>
        </div>
    );
}

function PhoneSummary({
    summary,
    filterDescription,
    onOpenAnalysis,
}: SummaryLayoutProps & { filterDescription: string[] }) {
    const caption = [countLabel(summary), filterDescription.join(', ')]
        .filter(Boolean)
        .join(' · ');

    return (
        <section
            aria-label={__('Filter summary')}
            className="overflow-hidden rounded-[14px] border bg-card"
        >
            <div className="flex items-end justify-between gap-3 px-3.5 py-3">
                <span className="flex min-w-0 flex-col gap-px">
                    <span className="truncate text-[13px] text-muted-foreground">
                        {caption}
                    </span>
                    <Amount
                        value={summary.total}
                        currency={summary.currency}
                        className="text-[22px] font-semibold"
                    />
                </span>
                <span className="flex shrink-0 flex-col items-end">
                    <span className="text-[13px] text-muted-foreground">
                        {__('Average')}
                    </span>
                    <Amount
                        value={summary.average}
                        currency={summary.currency}
                        className="font-medium"
                    />
                </span>
            </div>
            <button
                type="button"
                onClick={onOpenAnalysis}
                className="flex min-h-11 w-full items-center gap-2.5 border-t bg-muted px-3.5 text-left text-sm font-medium transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
            >
                <TrendBars
                    trend={summary.trend.slice(-PHONE_TREND_BARS)}
                    className="h-5 w-7 gap-0.5 [&>span]:rounded-[1px]"
                />
                {__('View analysis')}
                <span className="truncate text-[13px] font-normal text-muted-foreground">
                    · {__('trend and breakdown')}
                </span>
                <ChevronRight aria-hidden className="ml-auto size-4 shrink-0" />
            </button>
        </section>
    );
}

/**
 * An amount in the summary's currency, or a dash when one of the rows had no
 * rate to convert it with.
 */
function Amount({
    value,
    currency,
    className,
}: {
    value: number | null;
    currency: string;
    className?: string;
}) {
    if (value === null) {
        return (
            <span
                className={cn('font-mono', className)}
                title={__('No exchange rate for some of these amounts')}
            >
                —
            </span>
        );
    }

    return (
        <AmountDisplay
            amountInCents={value}
            currencyCode={currency}
            monospace
            className={className}
        />
    );
}

/**
 * The trend as tiny bars, each as tall as its month's figure next to the
 * biggest, the last one (the filter's own month) drawn darker.
 */
function TrendBars({
    trend,
    className,
}: {
    trend: Summary['trend'];
    className?: string;
}) {
    const sizes = trend.map((month) => Math.abs(month.total ?? 0));
    const biggest = Math.max(...sizes, 1);

    return (
        <span
            aria-hidden
            data-testid="trend-bars"
            className={cn('flex shrink-0 items-end', className)}
        >
            {sizes.map((size, index) => (
                <span
                    key={trend[index].month}
                    className={cn(
                        'flex-1',
                        index === sizes.length - 1
                            ? 'bg-foreground'
                            : 'bg-neutral-300 dark:bg-neutral-600',
                    )}
                    style={{
                        height: `${Math.max((size / biggest) * 100, 8)}%`,
                    }}
                />
            ))}
        </span>
    );
}

function TransactionFilterSummarySkeleton({ isMobile }: { isMobile: boolean }) {
    return (
        <div
            data-testid="filter-summary-skeleton"
            className={cn(
                'overflow-hidden border bg-card',
                isMobile
                    ? 'flex flex-col gap-2 rounded-[14px] px-3.5 py-3'
                    : 'grid grid-cols-[repeat(3,minmax(0,1fr))_minmax(0,1.25fr)] gap-6 rounded-xl px-[18px] py-3.5',
            )}
        >
            {Array.from({ length: isMobile ? 2 : 4 }, (_, index) => (
                <div key={index} className="flex flex-col gap-2">
                    <Skeleton className="h-3 w-24" />
                    <Skeleton className="h-6 w-20" />
                </div>
            ))}
        </div>
    );
}

function totalLabel(kind: Summary['kind']): string {
    switch (kind) {
        case 'expense':
            return __('Total spending');
        case 'income':
            return __('Total income');
        default:
            return __('Total');
    }
}

/** How many rows, named for what they are: expenses, income or both. */
function countLabel({ count, kind }: Summary): string {
    if (kind === 'expense') {
        return count === 1 ? __('1 expense') : __(':count expenses', { count });
    }

    if (kind === 'income') {
        return count === 1 ? __('1 income') : __(':count incomes', { count });
    }

    return count === 1
        ? __('1 transaction')
        : __(':count transactions', { count });
}
