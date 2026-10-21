import {
    IncomeFigure,
    PeriodTrend,
    shareOf,
    signTone,
    SummaryCardSkeleton,
    SummaryFigure,
    SummaryHeadline,
} from '@/components/cashflow/summary-card-parts';
import { AmountDisplay } from '@/components/ui/amount-display';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { CashflowSummary } from '@/hooks/use-cashflow-data';
import { allocatedOf, availableOf, freeOf } from '@/lib/income-distribution';
import { __ } from '@/utils/i18n';

interface AvailableCardProps {
    current: CashflowSummary;
    previous: CashflowSummary;
    loading?: boolean;
    currency?: string;
    className?: string;
}

/** Available minus what the open budgets still hold, with what it holds. */
function FreeHighlight({
    summary,
    currency,
}: {
    summary: CashflowSummary;
    currency: string;
}) {
    const reserved = summary.reserved ?? 0;
    const free = freeOf(summary);

    return (
        <div className="mt-3 flex items-center justify-between gap-4 rounded-md bg-muted/60 px-3 py-2">
            <div>
                <p className="text-sm font-medium">{__('Free (cashflow)')}</p>
                <p className="text-xs text-muted-foreground">
                    {reserved > 0 ? (
                        <>
                            <AmountDisplay
                                amountInCents={reserved}
                                currencyCode={currency}
                                minimumFractionDigits={0}
                                maximumFractionDigits={0}
                                className="text-xs"
                            />{' '}
                            {__('reserved in budgets')}
                        </>
                    ) : (
                        __('Nothing reserved in budgets')
                    )}
                </p>
            </div>
            <AmountDisplay
                amountInCents={free}
                currencyCode={currency}
                size="2xl"
                weight="bold"
                minimumFractionDigits={0}
                maximumFractionDigits={0}
                className={signTone(free)}
                highlightPositive
            />
        </div>
    );
}

export function AvailableCard({
    current,
    previous,
    loading,
    currency = 'USD',
    className,
}: AvailableCardProps) {
    if (loading) {
        return (
            <SummaryCardSkeleton
                title={__('Available')}
                className={className}
            />
        );
    }

    const available = availableOf(current);
    const rate = shareOf(available, current.income);
    const rateDiff = rate - shareOf(availableOf(previous), previous.income);
    const availableDiff = available - availableOf(previous);
    const allocatedDiff = allocatedOf(current) - allocatedOf(previous);
    const hasPreviousData = previous.income > 0 || previous.expense > 0;

    return (
        <Card className={className}>
            <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium">
                    {__('Available')}
                </CardTitle>
                <CardDescription>
                    {__('Income minus what was allocated')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <SummaryHeadline
                    amount={available}
                    currency={currency}
                    rate={rate}
                    amountClassName={signTone(available)}
                />
                {hasPreviousData && (
                    <PeriodTrend
                        rateDiff={rateDiff}
                        amountDiff={availableDiff}
                        currency={currency}
                    />
                )}
                <div className="mt-3 grid grid-cols-2 gap-4 border-t pt-3">
                    <IncomeFigure
                        current={current}
                        previous={previous}
                        currency={currency}
                        hasPreviousData={hasPreviousData}
                    />
                    <SummaryFigure
                        label={__('Allocated')}
                        caption={__('Expenses + contributed')}
                        amount={allocatedOf(current)}
                        currency={currency}
                        comparison={
                            hasPreviousData
                                ? {
                                      diff: allocatedDiff,
                                      isFavorable: allocatedDiff <= 0,
                                  }
                                : null
                        }
                    />
                </div>
                <FreeHighlight summary={current} currency={currency} />
            </CardContent>
        </Card>
    );
}
