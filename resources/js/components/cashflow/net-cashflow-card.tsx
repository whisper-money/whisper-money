import {
    IncomeFigure,
    PeriodTrend,
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
import { hasPeriodData } from '@/lib/cashflow-available';
import { __ } from '@/utils/i18n';

interface NetCashflowCardProps {
    current: CashflowSummary;
    previous: CashflowSummary;
    loading?: boolean;
    currency?: string;
}

export function NetCashflowCard({
    current,
    previous,
    loading,
    currency = 'USD',
}: NetCashflowCardProps) {
    if (loading) {
        return <SummaryCardSkeleton title={__('Net Cashflow')} />;
    }

    const diff = current.net - previous.net;
    const rateDiff = current.savings_rate - previous.savings_rate;
    const expenseDiff = current.expense - previous.expense;
    const hasPreviousData = hasPeriodData(previous);

    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium">
                    {__('Net Cashflow')}
                </CardTitle>
                <CardDescription>{__('Income minus expenses')}</CardDescription>
            </CardHeader>
            <CardContent>
                <SummaryHeadline
                    amount={current.net}
                    currency={currency}
                    rate={current.savings_rate}
                    amountClassName={signTone(current.net)}
                />
                {hasPreviousData && (
                    <PeriodTrend
                        rateDiff={rateDiff}
                        amountDiff={diff}
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
                        label={__('Expenses')}
                        amount={current.expense}
                        currency={currency}
                        comparison={
                            hasPreviousData
                                ? {
                                      diff: expenseDiff,
                                      isFavorable: expenseDiff <= 0,
                                  }
                                : null
                        }
                    />
                </div>
            </CardContent>
        </Card>
    );
}
