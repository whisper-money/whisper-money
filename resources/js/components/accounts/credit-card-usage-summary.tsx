import { AmountDisplay } from '@/components/ui/amount-display';
import { Progress } from '@/components/ui/progress';
import { cn } from '@/lib/utils';
import { type CreditCardUsage } from '@/types/account';
import { __ } from '@/utils/i18n';

interface CreditCardUsageSummaryProps {
    usage: CreditCardUsage;
    /** The card's own currency: usage is never converted. */
    currencyCode: string;
    weight?: 'medium' | 'bold';
    className?: string;
}

/**
 * What a credit card shows where other accounts show their balance: how much
 * of it is in use, against its limit when one is set.
 */
export function CreditCardUsageSummary({
    usage,
    currencyCode,
    weight = 'bold',
    className,
}: CreditCardUsageSummaryProps) {
    const { limit, used } = usage;
    const isOverLimit = limit !== null && used > limit;
    const usedPercentage =
        limit !== null && limit > 0 ? (used / limit) * 100 : 0;

    return (
        <div className={cn('flex flex-col gap-1', className)}>
            <AmountDisplay
                amountInCents={used}
                currencyCode={currencyCode}
                size="2xl"
                weight={weight}
            />
            {limit === null ? (
                <span className="text-sm text-muted-foreground">
                    {__('In use')}
                </span>
            ) : (
                <>
                    <span className="flex items-center gap-1 text-sm text-muted-foreground">
                        {__('In use of')}
                        <AmountDisplay
                            amountInCents={limit}
                            currencyCode={currencyCode}
                        />
                    </span>
                    <Progress
                        value={usedPercentage}
                        aria-label={__('Credit limit in use')}
                        className="h-1.5 w-32"
                        indicatorClassName={cn(
                            isOverLimit && 'bg-red-600 dark:bg-red-400',
                        )}
                    />
                </>
            )}
        </div>
    );
}
