import { AmountDisplay } from '@/components/ui/amount-display';
import { cn } from '@/lib/utils';
import { type AchievementFigureValue } from '@/types';
import { __ } from '@/utils/i18n';

/**
 * A run of months, written so one month is not "1 months". Shared with the
 * overview, which says the same thing about the live streak.
 */
export function monthsLabel(count: number): string {
    return count === 1 ? __('1 month') : __(':count months', { count });
}

/**
 * A medal's number.
 *
 * Amounts go through `AmountDisplay` rather than being written server-side, so
 * privacy mode blanks the digits here like it does everywhere else: an
 * achievement is not a hole in the mask through which someone's net worth can be
 * read over their shoulder.
 */
export function AchievementFigure({
    figure,
    className,
}: {
    figure: AchievementFigureValue | null;
    className?: string;
}) {
    if (figure === null) {
        return null;
    }

    if (figure.type === 'money' && figure.currency) {
        return (
            <AmountDisplay
                amountInCents={figure.value}
                currencyCode={figure.currency}
                // A milestone is a round number, so it is written without
                // decimals. Both bounds have to move together: leaving the
                // minimum at the currency's own scale asks Intl for two
                // decimals and at most none, which it refuses outright.
                minimumFractionDigits={0}
                maximumFractionDigits={0}
                className={cn('tabular-nums', className)}
            />
        );
    }

    const label =
        figure.type === 'percent'
            ? `${figure.value}%`
            : figure.type === 'months'
              ? monthsLabel(figure.value)
              : figure.value.toLocaleString();

    return <span className={cn('tabular-nums', className)}>{label}</span>;
}
