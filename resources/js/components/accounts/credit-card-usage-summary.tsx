import { AmountDisplay } from '@/components/ui/amount-display';
import { Progress } from '@/components/ui/progress';
import {
    StatTile,
    StatTileDescription,
    StatTileValue,
} from '@/components/ui/stat-tile';
import { cn } from '@/lib/utils';
import { type CreditCardUsage } from '@/types/account';
import { __ } from '@/utils/i18n';

interface CreditCardUsageSummaryProps {
    usage: CreditCardUsage;
    /** The card's own currency: usage is never converted. */
    currencyCode: string;
    className?: string;
}

/**
 * What a credit card shows where other accounts show their balance: how much
 * of it is in use, against its limit when one is set.
 */
export function CreditCardUsageSummary({
    usage,
    currencyCode,
    className,
}: CreditCardUsageSummaryProps) {
    const { limit, used } = usage;
    const isOverLimit = limit !== null && used > limit;
    const usedPercentage =
        limit !== null && limit > 0 ? (used / limit) * 100 : 0;

    return (
        <StatTile size="lg" className={className}>
            <StatTileValue>
                <AmountDisplay
                    amountInCents={used}
                    currencyCode={currencyCode}
                />
            </StatTileValue>
            {limit === null ? (
                <StatTileDescription>{__('In use')}</StatTileDescription>
            ) : (
                <>
                    <StatTileDescription className="flex items-center gap-1">
                        {__('In use of')}
                        <AmountDisplay
                            amountInCents={limit}
                            currencyCode={currencyCode}
                        />
                    </StatTileDescription>
                    <Progress
                        value={usedPercentage}
                        aria-label={__('Credit limit in use')}
                        className="h-1.5 w-32"
                        indicatorClassName={cn(isOverLimit && 'bg-warning')}
                    />
                </>
            )}
        </StatTile>
    );
}
