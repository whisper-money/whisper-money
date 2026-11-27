import { ReviewStepLayout } from '@/components/forecast/forecast-review-layout';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { categorizeHref } from '@/lib/categorize-href';
import { type ForecastReviewUncategorized } from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';

interface UncategorizedNoticeProps {
    uncategorized: ForecastReviewUncategorized;
    /** The accounts counted, so the list shows only their transactions. */
    accountIds: string[];
    onSkip: () => void;
    onContinue: () => void;
}

/**
 * Shown before the first step when more than a fifth of the money of the
 * last three months has no category: the forecast estimates by category,
 * so that money only lands in "Other". It links to those transactions,
 * already filtered, or lets the user go on anyway.
 */
export function ForecastReviewUncategorized({
    uncategorized,
    accountIds,
    onSkip,
    onContinue,
}: UncategorizedNoticeProps) {
    const { formatAmount } = useFormatAmount();
    const monthly = formatAmount(
        uncategorized.monthlyAmount,
        uncategorized.currencyCode,
        { fractionDigits: 0 },
    );

    return (
        <ReviewStepLayout
            eyebrow={__('Before you start · Forecast')}
            title={__('Your forecast is worked out by category')}
            description={__(
                'We estimate each month from what every category usually moves, so the money without a category can only go to "Other spending" or "Other income".',
            )}
            footer={
                <div className="ml-auto flex gap-2">
                    <Button variant="outline" onClick={onSkip}>
                        {__('Not now')}
                    </Button>
                    <Button
                        onClick={onContinue}
                        data-test="forecast-review-continue"
                    >
                        {__('Continue anyway')}
                    </Button>
                </div>
            }
        >
            <Alert
                variant="warning"
                className="mt-2"
                data-test="forecast-review-uncategorized"
            >
                <TriangleAlert />
                <AlertTitle>
                    {uncategorized.count === 1
                        ? __(
                              'You have 1 uncategorized transaction (:amount/month).',
                              { amount: monthly },
                          )
                        : __(
                              'You have :count uncategorized transactions (:amount/month).',
                              { count: uncategorized.count, amount: monthly },
                          )}
                </AlertTitle>
                <AlertDescription className="flex flex-col items-start gap-3">
                    {__('Categorizing them makes your forecast more accurate.')}
                    <Button asChild size="sm" variant="outline">
                        <Link
                            href={categorizeHref({
                                period: {
                                    from: uncategorized.from,
                                    to: uncategorized.to,
                                },
                                accountIds,
                            })}
                            data-test="forecast-review-categorize"
                        >
                            {__('Categorize them')}
                        </Link>
                    </Button>
                </AlertDescription>
            </Alert>
        </ReviewStepLayout>
    );
}
