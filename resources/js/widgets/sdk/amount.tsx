import { cn } from '@/lib/utils';
import { maskDigits } from '@/utils/currency';
import { useWidgetEnv } from './context';
import { formatMoney } from './format-money';
import { classNameOf } from './props';

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
    className?: unknown;
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
            className={cn('tabular-nums', classNameOf(className))}
        >
            {env.privacyMode ? maskDigits(formatted) : formatted}
        </span>
    );
}
