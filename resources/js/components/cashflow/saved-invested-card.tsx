import { AmountDisplay } from '@/components/ui/amount-display';
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
import { HelpCircle, TrendingDown, TrendingUp } from 'lucide-react';

interface SavedInvestedCardProps {
    current: CashflowSummary;
    previous: CashflowSummary;
    loading?: boolean;
    currency?: string;
}

interface PercentageComparisonProps {
    diff: number;
}

function PercentageComparison({ diff }: PercentageComparisonProps) {
    const isPositive = diff >= 0;
    const Icon = isPositive ? TrendingUp : TrendingDown;

    return (
        <div className="mt-2 flex items-center gap-1 text-sm">
            <Icon
                className={cn(
                    'size-4',
                    isPositive
                        ? 'text-green-600 dark:text-green-400'
                        : 'text-red-600 dark:text-red-400',
                )}
            />
            <span>
                {isPositive ? '+' : ''}
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
}

function AmountComparison({ diff, currency }: AmountComparisonProps) {
    const isPositive = diff >= 0;
    const Icon = isPositive ? TrendingUp : TrendingDown;

    return (
        <div className="mt-1 flex items-center gap-1 text-xs">
            <Icon
                className={cn(
                    'size-3',
                    isPositive
                        ? 'text-green-600 dark:text-green-400'
                        : 'text-red-600 dark:text-red-400',
                )}
            />
            <span>
                {isPositive ? '+' : ''}
                <AmountDisplay
                    amountInCents={diff}
                    currencyCode={currency}
                    minimumFractionDigits={0}
                    maximumFractionDigits={0}
                    className="text-xs"
                    highlightPositive
                />
            </span>
            <span className="text-muted-foreground">
                {__('vs last period')}
            </span>
        </div>
    );
}

function allocationRate(summary: CashflowSummary): number {
    if (summary.net <= 0) {
        return 0;
    }

    return ((summary.savings + summary.investments) / summary.net) * 100;
}

export function SavedInvestedCard({
    current,
    previous,
    loading,
    currency = 'USD',
}: SavedInvestedCardProps) {
    if (loading) {
        return (
            <Card>
                <CardHeader className="pb-2">
                    <CardTitle className="text-sm font-medium">
                        {__('Saved & Invested')}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="h-12 w-32 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                </CardContent>
            </Card>
        );
    }

    const allocated = current.savings + current.investments;
    const previousAllocated = previous.savings + previous.investments;
    const allocatedDiff = allocated - previousAllocated;

    const rate = allocationRate(current);
    const rateDiff = rate - allocationRate(previous);

    const savingsDiff = current.savings - previous.savings;
    const investmentsDiff = current.investments - previous.investments;
    const hasPreviousData = previous.income > 0;

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
                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <AmountDisplay
                        amountInCents={allocated}
                        currencyCode={currency}
                        size="4xl"
                        weight="bold"
                        minimumFractionDigits={0}
                        maximumFractionDigits={0}
                        className={rateColor}
                        highlightPositive
                    />
                    <span
                        className={cn(
                            'text-lg font-medium tabular-nums',
                            rateColor,
                        )}
                    >
                        {rate.toFixed(1)}%
                    </span>
                </div>
                {hasPreviousData && (
                    <>
                        <PercentageComparison diff={rateDiff} />
                        <AmountComparison
                            diff={allocatedDiff}
                            currency={currency}
                        />
                    </>
                )}
                <div className="mt-3 grid grid-cols-2 gap-4 border-t pt-3">
                    <div>
                        <p className="text-xs text-muted-foreground">
                            {__('Saved (cashflow)')}
                        </p>
                        <AmountDisplay
                            amountInCents={current.savings}
                            currencyCode={currency}
                            minimumFractionDigits={0}
                            maximumFractionDigits={0}
                            weight="medium"
                            highlightPositive
                        />
                        {hasPreviousData && (
                            <AmountComparison
                                diff={savingsDiff}
                                currency={currency}
                            />
                        )}
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">
                            {__('Invested')}
                        </p>
                        <AmountDisplay
                            amountInCents={current.investments}
                            currencyCode={currency}
                            minimumFractionDigits={0}
                            maximumFractionDigits={0}
                            weight="medium"
                            highlightPositive
                        />
                        {hasPreviousData && (
                            <AmountComparison
                                diff={investmentsDiff}
                                currency={currency}
                            />
                        )}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
