import {
    IncomeFigure,
    PeriodTrend,
    shareOf,
    signTone,
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
import { CashflowSummary } from '@/hooks/use-cashflow-data';
import { __ } from '@/utils/i18n';

interface AvailableCardProps {
    current: CashflowSummary;
    previous: CashflowSummary;
    loading?: boolean;
    currency?: string;
    className?: string;
}

/** Expenses plus what was saved or invested. */
function allocatedOf(summary: CashflowSummary): number {
    return summary.expense + summary.savings + summary.investments;
}

/** Income minus what was allocated: the Sankey's center node. */
function availableOf(summary: CashflowSummary): number {
    return summary.net - summary.savings - summary.investments;
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
            </CardContent>
        </Card>
    );
}
