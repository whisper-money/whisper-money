import { AmountDisplay } from '@/components/ui/amount-display';
import { cn } from '@/lib/utils';
import { type AchievementFigureValue } from '@/types';
import { __ } from '@/utils/i18n';

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
                maximumFractionDigits={0}
                className={cn('tabular-nums', className)}
            />
        );
    }

    const label =
        figure.type === 'percent'
            ? `${figure.value}%`
            : figure.type === 'months'
              ? __(':count months', { count: figure.value })
              : figure.value.toLocaleString();

    return <span className={cn('tabular-nums', className)}>{label}</span>;
}
