import { StackedBar } from '@/components/cashflow/cashflow-blocks';
import {
    INVESTMENTS_TONE,
    PeriodTrend,
    SAVINGS_TONE,
    shareOf,
    SummaryCardSkeleton,
    SummaryFigure,
    SummaryHeadline,
} from '@/components/cashflow/summary-card-parts';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { CashflowSummary } from '@/hooks/use-cashflow-data';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';
import { HelpCircle } from 'lucide-react';

interface SavedInvestedCardProps {
    current: CashflowSummary;
    previous: CashflowSummary;
    loading?: boolean;
    currency?: string;
    /** Adds each one's share of what was contributed, as pills and a bar. */
    showContributionSplit?: boolean;
}

function allocationRate(summary: CashflowSummary): number {
    return shareOf(summary.savings + summary.investments, summary.net);
}

interface SharePillProps {
    share: number;
    tone: string;
}

/** A side's share of what was contributed. */
function SharePill({ share, tone }: SharePillProps) {
    return (
        <span className="flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800 tabular-nums dark:bg-green-950 dark:text-green-200">
            <span aria-hidden className={cn('size-1.5 rounded-full', tone)} />
            {share.toFixed(0)}%
        </span>
    );
}

interface ContributionSplitBarProps {
    savingsShare: number;
    investmentsShare: number;
}

function ContributionSplitBar({
    savingsShare,
    investmentsShare,
}: ContributionSplitBarProps) {
    return (
        <StackedBar
            ariaLabel={__(
                'Contributed split: :saved% saved, :invested% invested',
                {
                    saved: savingsShare.toFixed(0),
                    invested: investmentsShare.toFixed(0),
                },
            )}
            className="mt-3"
            segments={[
                {
                    key: 'saved',
                    label: __('Saved (cashflow)'),
                    amount: savingsShare,
                    tone: SAVINGS_TONE,
                },
                {
                    key: 'invested',
                    label: __('Invested'),
                    amount: investmentsShare,
                    tone: INVESTMENTS_TONE,
                },
            ]}
        />
    );
}

export function SavedInvestedCard({
    current,
    previous,
    loading,
    currency = 'USD',
    showContributionSplit = false,
}: SavedInvestedCardProps) {
    if (loading) {
        return <SummaryCardSkeleton title={__('Saved & Invested')} />;
    }

    const contributed = current.savings + current.investments;
    const previousContributed = previous.savings + previous.investments;
    const contributedDiff = contributed - previousContributed;

    const rate = allocationRate(current);
    const rateDiff = rate - allocationRate(previous);

    const savingsDiff = current.savings - previous.savings;
    const investmentsDiff = current.investments - previous.investments;
    const hasPreviousData = previous.income > 0;
    // Shares only read when both sides put money in: money pulled out of
    // savings or investments leaves no split that adds up to 100%.
    const showSplit =
        showContributionSplit &&
        contributed > 0 &&
        current.savings >= 0 &&
        current.investments >= 0;
    const savingsShare = showSplit
        ? shareOf(current.savings, contributed)
        : null;
    const investmentsShare = showSplit
        ? shareOf(current.investments, contributed)
        : null;

    // Determine color based on how much of the net cashflow was set aside
    const rateColor =
        rate >= 50
            ? 'text-green-600 dark:text-green-400'
            : rate >= 25
              ? 'text-yellow-600 dark:text-yellow-400'
              : rate >= 0
                ? 'text-orange-600 dark:text-orange-400'
                : 'text-red-600 dark:text-red-400';

    return (
        <Card>
            <CardHeader className="relative pb-2">
                <CardTitle className="text-sm font-medium">
                    {__('Saved & Invested')}
                </CardTitle>
                <CardDescription>
                    {__('Share of net cashflow set aside')}
                </CardDescription>
                <Popover>
                    <PopoverTrigger
                        aria-label={__('Where do these numbers come from?')}
                        className="absolute top-0 right-6 text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <HelpCircle className="size-4" />
                    </PopoverTrigger>
                    <PopoverContent align="end" className="text-sm">
                        {__(
                            'These figures come from transactions categorized with a "saving" or "investment" category type. Make sure you use those category types so all the money set aside is counted here.',
                        )}
                    </PopoverContent>
                </Popover>
            </CardHeader>
            <CardContent>
                <SummaryHeadline
                    amount={contributed}
                    currency={currency}
                    rate={rate}
                    amountClassName={rateColor}
                    rateClassName={rateColor}
                />
                {hasPreviousData && (
                    <PeriodTrend
                        rateDiff={rateDiff}
                        amountDiff={contributedDiff}
                        currency={currency}
                    />
                )}
                <div className="mt-3 grid grid-cols-2 gap-4 border-t pt-3">
                    <SummaryFigure
                        label={__('Saved (cashflow)')}
                        badge={
                            savingsShare !== null && (
                                <SharePill
                                    share={savingsShare}
                                    tone={SAVINGS_TONE}
                                />
                            )
                        }
                        amount={current.savings}
                        currency={currency}
                        highlightPositive
                        comparison={
                            hasPreviousData ? { diff: savingsDiff } : null
                        }
                    />
                    <SummaryFigure
                        label={__('Invested')}
                        badge={
                            investmentsShare !== null && (
                                <SharePill
                                    share={investmentsShare}
                                    tone={INVESTMENTS_TONE}
                                />
                            )
                        }
                        amount={current.investments}
                        currency={currency}
                        highlightPositive
                        comparison={
                            hasPreviousData ? { diff: investmentsDiff } : null
                        }
                    />
                </div>
                {savingsShare !== null && investmentsShare !== null && (
                    <ContributionSplitBar
                        savingsShare={savingsShare}
                        investmentsShare={investmentsShare}
                    />
                )}
            </CardContent>
        </Card>
    );
}
