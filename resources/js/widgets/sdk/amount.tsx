import { cn } from '@/lib/utils';
import { useWidgetEnv } from './context';
import { formatMoney } from './format-money';

/** Privacy mode hides the figures, as AmountDisplay does across the app. */
export function maskDigits(text: string, privacyMode: boolean): string {
    return privacyMode ? text.replace(/\d/g, '*') : text;
}

/**
 * An amount in minor units, in the widget's currency unless it names one.
 * While privacy mode is on its digits are masked like every other amount in
 * the app: the real figure never reaches the DOM.
 */
export function Amount({
    amount,
    currency,
    showSign = false,
    compact = false,
    className,
}: {
    amount: unknown;
    currency?: unknown;
    showSign?: unknown;
    compact?: unknown;
    className?: string;
}) {
    const env = useWidgetEnv();
    const formatted = formatMoney(amount, currency ?? env.currency, {
        showSign: showSign === true,
        compact: compact === true,
        locale: env.locale,
    });

    return (
        <span
            data-slot="widget-amount"
            className={cn('tabular-nums', className)}
        >
            {maskDigits(formatted, env.privacyMode)}
        </span>
    );
}
